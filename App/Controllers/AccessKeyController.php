<?php

namespace App\Controllers;

use App\Channels\DevicesChannel;
use App\Connector\ConnectorPackager;
use App\Middlewares\IsAuthenticated;
use App\Requests\CreateAccessKeyRequest;
use App\Requests\PreconfiguredConnectorRequest;
use App\Resources\AccessKeyResource;
use App\Services\AccessKeyService;
use App\Services\HistoryService;
use App\Services\WorkspaceService;
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

class AccessKeyController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected WorkspaceService $workspaces,
        protected AccessKeyService $keys,
        protected ConnectorPackager $connector,
        protected DevicesChannel $devices,
        protected HistoryService $history,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/access-keys', [self::class, 'list']),
            Route::post('/access-keys', [self::class, 'create']),
            Route::delete('/access-keys/{int:key}', [self::class, 'revoke']),
            Route::post('/access-keys/{int:key}/connector', [self::class, 'downloadPreconfigured']),
            Route::get('/connector', [self::class, 'download']),
        ]);
    }

    #[Endpoint('List access keys', 'The usable access keys of the current workspace', 'listAccessKeys')]
    #[ResourceResponse(AccessKeyResource::class, isArray: true, description: 'The access keys')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function list(Request $request): Response
    {
        return Response::json(AccessKeyResource::of($this->keys->listFor($this->currentWorkspaceId())));
    }

    #[Endpoint('Create access key', 'Creates an access key in the current workspace. The secret is only given by this answer', 'createAccessKey')]
    #[ObjectResponse([
        'key' => AccessKeyResource::class,
        'config' => ['tower_url' => 'ws://localhost:8088/devices', 'client_id' => 'twr_abcdef', 'secret' => 'abcdef'],
    ], responseCode: StatusCode::CREATED, description: 'The key and its connector configuration')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function create(CreateAccessKeyRequest $request): Response
    {
        [$key, $secret] = $this->keys->create($this->currentWorkspaceId(), $request->validated('name'));
        $this->history->log($key->workspace, 'key.created', ['name' => $key->name], user: $this->authentication->userId());

        return Response::json([
            'key' => AccessKeyResource::of($key),
            'config' => $this->connector->configFor($key, $secret),
        ], StatusCode::CREATED);
    }

    #[Endpoint('Revoke access key', 'Revokes a key of the current workspace and disconnects the devices using it', 'revokeAccessKey')]
    #[NoContentResponse('The key is revoked')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Access key not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such usable key in the current workspace')]
    public function revoke(Request $request, int $key): Response
    {
        $accessKey = $this->keys->findUsable($this->currentWorkspaceId(), $key);
        if (!$accessKey)
            return Response::json(['error' => 'Access key not found'], StatusCode::NOT_FOUND);

        $this->keys->revoke($accessKey);
        $this->history->log($accessKey->workspace, 'key.revoked', ['name' => $accessKey->name], user: $this->authentication->userId());
        $this->devices->disconnectKey($accessKey->id, 'revoked');

        return Response::noContent();
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

    #[Endpoint('Download preconfigured connector', 'The Tower connector with this key embedded : it runs without a configuration file. Needs the secret given at creation', 'downloadPreconfiguredConnector')]
    #[RawResponse(mimeType: 'application/octet-stream', description: 'tower-connector-preconfigured.pyz')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Access key not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such usable key in the current workspace, or wrong secret')]
    #[RawResponse(['error' => 'Connector unavailable'], responseCode: StatusCode::SERVICE_UNAVAILABLE, description: 'The connector was not built into this image')]
    public function downloadPreconfigured(PreconfiguredConnectorRequest $request, int $key): Response
    {
        $secret = $request->validated('secret');
        $accessKey = $this->keys->findUsable($this->currentWorkspaceId(), $key);
        if (!$accessKey || !$this->keys->secretMatches($accessKey, $secret))
            return Response::json(['error' => 'Access key not found'], StatusCode::NOT_FOUND);

        if (!$this->connector->isAvailable())
            return $this->connectorUnavailable();

        return new Response(StatusCode::OK, $this->connector->withEmbeddedConfig($this->connector->configFor($accessKey, $secret)), [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="tower-connector-preconfigured.pyz"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function connectorUnavailable(): Response
    {
        return Response::json(['error' => 'Connector unavailable'], StatusCode::SERVICE_UNAVAILABLE);
    }

    private function currentWorkspaceId(): int
    {
        return $this->workspaces->current($this->authentication->userId())->id;
    }
}
