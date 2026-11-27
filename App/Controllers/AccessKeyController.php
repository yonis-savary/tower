<?php

namespace App\Controllers;

use App\Channels\DevicesChannel;
use App\Connector\ConfigDownloadService;
use App\Connector\ConnectorPackager;
use App\Connector\HandlerRules;
use App\Models\AccessKey;
use App\Middlewares\IsAuthenticated;
use App\Requests\CreateAccessKeyRequest;
use App\Requests\CreateDownloadLinkRequest;
use App\Requests\ListenRequest;
use App\Requests\PreconfiguredConnectorRequest;
use App\Requests\UpdateHandlersRequest;
use App\Resources\AccessKeyResource;
use App\Services\AccessKeyService;
use App\Services\HistoryService;
use Cube\Data\OpenAPI\Attributes\Endpoint;
use Cube\Data\OpenAPI\Attributes\NoContentResponse;
use Cube\Data\OpenAPI\Attributes\ObjectResponse;
use Cube\Data\OpenAPI\Attributes\RawResponse;
use Cube\Data\OpenAPI\Attributes\ResourceResponse;
use Cube\Security\Authentication;
use Cube\Web\Controller;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Http\StatusCode;
use Cube\Web\Router\Route;
use Cube\Web\Router\Router;

use function App\currentWorkspaceId;

class AccessKeyController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected AccessKeyService $keys,
        protected ConnectorPackager $connector,
        protected DevicesChannel $devices,
        protected HistoryService $history,
        protected ConfigDownloadService $downloads,
    ) {}

    /** The answer of every route that gives a secret : the key, and the configuration to give to the connector */
    private const CONFIG_EXAMPLE = [
        'key' => AccessKeyResource::class,
        'config' => ['tower_url' => 'ws://localhost:8088/devices', 'client_id' => 'twr_abcdef', 'secret' => 'abcdef'],
    ];

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/access-keys', [self::class, 'list']),
            Route::post('/access-keys', [self::class, 'create']),
            Route::delete('/access-keys/{int:key}', [self::class, 'revoke']),
            Route::put('/access-keys/{int:key}/listen', [self::class, 'updateListen']),
            Route::put('/access-keys/{int:key}/handlers', [self::class, 'updateHandlers']),
            Route::post('/access-keys/{int:key}/secret', [self::class, 'rotateSecret']),
            Route::post('/access-keys/{int:key}/connector', [self::class, 'downloadPreconfigured']),
            Route::post('/access-keys/{int:key}/download-links', [self::class, 'createDownloadLink']),
            Route::get('/connector', [self::class, 'download']),
            Route::get('/connector/handler', [self::class, 'downloadHandler']),
        ]);

        // Called from a terminal : the single-use token is the authentication, not a session
        $router->addRoutes(
            Route::get('/api/config-links/{token}', [self::class, 'downloadFromLink']),
        );
    }

    #[Endpoint('List access keys', 'The usable access keys of the current workspace', 'listAccessKeys')]
    #[ResourceResponse(AccessKeyResource::class, isArray: true, description: 'The access keys')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function list(Request $request): Response
    {
        return Response::json(AccessKeyResource::of($this->keys->listFor(currentWorkspaceId())));
    }

    #[Endpoint('Create access key', 'Creates an access key in the current workspace. The secret is only given by this answer', 'createAccessKey')]
    #[ObjectResponse(self::CONFIG_EXAMPLE, responseCode: StatusCode::CREATED, description: 'The key and its connector configuration')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function create(CreateAccessKeyRequest $request): Response
    {
        $validated = $request->validated();
        [$key, $secret] = $this->keys->create(
            currentWorkspaceId(),
            $validated['name'],
            $validated['listen'] ?? AccessKeyService::LISTEN_EVERYTHING,
            HandlerRules::normalize($validated['handlers'] ?? []),
        );
        $this->history->log($key->workspace, 'key.created', ['name' => $key->name], user: $this->authentication->userId());

        return $this->withConfig($key, $secret, StatusCode::CREATED);
    }

    #[Endpoint('Update handlers', 'Replaces the handler rules written into the configuration of this key. Gives it a new secret : the devices using it are disconnected, and need the new configuration', 'updateAccessKeyHandlers')]
    #[ObjectResponse(self::CONFIG_EXAMPLE, description: 'The key and its new connector configuration')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Access key not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such usable key in the current workspace')]
    public function updateHandlers(UpdateHandlersRequest $request, int $key): Response
    {
        $accessKey = $this->keys->findUsable(currentWorkspaceId(), $key);
        if (!$accessKey)
            return $this->keyNotFound();

        $secret = $this->keys->setHandlers($accessKey, HandlerRules::normalize($request->validated('handlers')));
        $this->history->log($accessKey->workspace, 'key.handlers_updated', ['name' => $accessKey->name], user: $this->authentication->userId());
        $this->devices->disconnectKey($accessKey->id, 'secret_rotated');

        return $this->withConfig($accessKey, $secret);
    }

    #[Endpoint('Rotate secret', 'Gives a new secret to this key. The devices using it are disconnected, and need the new configuration', 'rotateAccessKeySecret')]
    #[ObjectResponse(self::CONFIG_EXAMPLE, description: 'The key and its new connector configuration')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Access key not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such usable key in the current workspace')]
    public function rotateSecret(Request $request, int $key): Response
    {
        $accessKey = $this->keys->findUsable(currentWorkspaceId(), $key);
        if (!$accessKey)
            return $this->keyNotFound();

        $secret = $this->keys->rotateSecret($accessKey);
        $this->history->log($accessKey->workspace, 'key.secret_rotated', ['name' => $accessKey->name], user: $this->authentication->userId());
        $this->devices->disconnectKey($accessKey->id, 'secret_rotated');

        return $this->withConfig($accessKey, $secret);
    }

    #[Endpoint('Create download link', 'A single-use link to download the configuration or the preconfigured connector from a terminal, without a session. Needs the secret, and expires after 15 minutes', 'createConfigDownloadLink')]
    #[RawResponse(['path' => '/api/config-links/abcdef', 'expires_at' => '2026-11-17T22:15:00+00:00'], responseCode: StatusCode::CREATED, description: 'The path of the link, to append to the address of Tower')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Access key not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such usable key in the current workspace, or wrong secret')]
    #[RawResponse(['error' => 'Connector unavailable'], responseCode: StatusCode::SERVICE_UNAVAILABLE, description: 'A connector is asked, and it was not built into this image')]
    public function createDownloadLink(CreateDownloadLinkRequest $request, int $key): Response
    {
        $validated = $request->validated();
        $secret = $validated['secret'];
        $format = $validated['format'];
        $accessKey = $this->keys->findUsable(currentWorkspaceId(), $key);
        if (!$accessKey || !$this->keys->secretMatches($accessKey, $secret))
            return $this->keyNotFound();

        if (ConfigDownloadService::FORMAT_PYZ === $format && !$this->connector->isAvailable())
            return $this->connectorUnavailable();

        $link = $this->downloads->create($accessKey, $format, $this->connector->configFor($accessKey, $secret));

        return Response::json(['path' => '/api/config-links/'.$link['token'], 'expires_at' => $link['expires_at']], StatusCode::CREATED);
    }

    #[Endpoint('Download from link', 'The configuration or the preconfigured connector behind a single-use link. Needs no session : the link only serves once', 'downloadFromConfigLink')]
    #[RawResponse(mimeType: 'application/octet-stream', description: 'tower-config.json or tower-connector.pyz')]
    #[RawResponse(['error' => 'Link not found'], responseCode: StatusCode::NOT_FOUND, description: 'Unknown, already used or expired link')]
    #[RawResponse(['error' => 'Connector unavailable'], responseCode: StatusCode::SERVICE_UNAVAILABLE, description: 'The connector was not built into this image')]
    public function downloadFromLink(Request $request, string $token): Response
    {
        $link = $this->downloads->consume($token);
        if (!$link)
            return Response::json(['error' => 'Link not found'], StatusCode::NOT_FOUND);

        if (ConfigDownloadService::FORMAT_JSON === $link['format'])
            return $this->attachment($this->connector->encode($link['config']), 'tower-config.json');

        if (!$this->connector->isAvailable())
            return $this->connectorUnavailable();

        return $this->attachment($this->connector->withEmbeddedConfig($link['config']), 'tower-connector.pyz');
    }

    #[Endpoint('Revoke access key', 'Revokes a key of the current workspace and disconnects the devices using it', 'revokeAccessKey')]
    #[NoContentResponse('The key is revoked')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Access key not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such usable key in the current workspace')]
    public function revoke(Request $request, int $key): Response
    {
        $accessKey = $this->keys->findUsable(currentWorkspaceId(), $key);
        if (!$accessKey)
            return $this->keyNotFound();

        $this->keys->revoke($accessKey);
        $this->history->log($accessKey->workspace, 'key.revoked', ['name' => $accessKey->name], user: $this->authentication->userId());
        $this->devices->disconnectKey($accessKey->id, 'revoked');

        return Response::noContent();
    }

    #[Endpoint('Update listened events', 'Changes the patterns of the events sent to the devices using this key. Applies without reconnecting', 'updateAccessKeyListen')]
    #[ResourceResponse(AccessKeyResource::class, description: 'The key')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Access key not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such usable key in the current workspace')]
    public function updateListen(ListenRequest $request, int $key): Response
    {
        $accessKey = $this->keys->findUsable(currentWorkspaceId(), $key);
        if (!$accessKey)
            return $this->keyNotFound();

        $this->keys->setListen($accessKey, $request->validated('listen'));

        return Response::json(AccessKeyResource::of($accessKey));
    }

    #[Endpoint('Download connector', 'The Tower connector, to run with a configuration file', 'downloadConnector')]
    #[RawResponse(mimeType: 'application/octet-stream', description: 'tower-connector.pyz')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Connector unavailable'], responseCode: StatusCode::SERVICE_UNAVAILABLE, description: 'The connector was not built into this image')]
    public function download(Request $request): Response
    {
        if (!$this->connector->isAvailable())
            return $this->connectorUnavailable();

        return Response::download($this->connector->archivePath(), attachmentFile: 'tower-connector.pyz');
    }

    #[Endpoint('Download basic handler', 'The basic handler of Tower events, run by the connector : ai.discuss opens a Claude Code discussion about a Jira issue', 'downloadHandler')]
    #[RawResponse(mimeType: 'application/octet-stream', description: 'tower-handler.pyz')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Connector unavailable'], responseCode: StatusCode::SERVICE_UNAVAILABLE, description: 'The handler was not built into this image')]
    public function downloadHandler(Request $request): Response
    {
        if (!$this->connector->isHandlerAvailable())
            return $this->connectorUnavailable();

        return Response::download($this->connector->handlerArchivePath(), attachmentFile: 'tower-handler.pyz');
    }

    #[Endpoint('Download preconfigured connector', 'The Tower connector with this key embedded : it runs without a configuration file. Needs the secret given at creation', 'downloadPreconfiguredConnector')]
    #[RawResponse(mimeType: 'application/octet-stream', description: 'tower-connector-preconfigured.pyz')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Access key not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such usable key in the current workspace, or wrong secret')]
    #[RawResponse(['error' => 'Connector unavailable'], responseCode: StatusCode::SERVICE_UNAVAILABLE, description: 'The connector was not built into this image')]
    public function downloadPreconfigured(PreconfiguredConnectorRequest $request, int $key): Response
    {
        $secret = $request->validated('secret');
        $accessKey = $this->keys->findUsable(currentWorkspaceId(), $key);
        if (!$accessKey || !$this->keys->secretMatches($accessKey, $secret))
            return $this->keyNotFound();

        if (!$this->connector->isAvailable())
            return $this->connectorUnavailable();

        return $this->attachment($this->connector->withEmbeddedConfig($this->connector->configFor($accessKey, $secret)), 'tower-connector-preconfigured.pyz');
    }

    private function withConfig(AccessKey $key, #[\SensitiveParameter] string $secret, int $status = StatusCode::OK): Response
    {
        return Response::json([
            'key' => AccessKeyResource::of($key),
            'config' => $this->connector->configFor($key, $secret),
        ], $status);
    }

    private function attachment(string $bytes, string $filename): Response
    {
        return new Response(StatusCode::OK, $bytes, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            // Holds a secret : no proxy or browser keeps it
            'Cache-Control' => 'no-store',
        ]);
    }

    private function keyNotFound(): Response
    {
        return Response::json(['error' => 'Access key not found'], StatusCode::NOT_FOUND);
    }

    private function connectorUnavailable(): Response
    {
        return Response::json(['error' => 'Connector unavailable'], StatusCode::SERVICE_UNAVAILABLE);
    }
}
