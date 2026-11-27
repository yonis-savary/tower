<?php

namespace App\Controllers;

use App\Middlewares\IsAuthenticated;
use App\Requests\CreateWebhookRequest;
use App\Requests\HistoryRequest;
use App\Requests\UpdateWebhookRequest;
use App\Resources\RefusedWebhookCallResource;
use App\Resources\WebhookCallResource;
use App\Resources\WebhookResource;
use App\Services\HistoryService;
use App\Services\WebhookService;
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

class WebhookController extends Controller
{
    private const RECENT_LIMIT = 15;

    private const CALL_RESPONSES = [
        WebhookService::CALL_ACCEPTED => [StatusCode::NO_CONTENT, null],
        WebhookService::CALL_UNAUTHORIZED => [StatusCode::UNAUTHORIZED, 'Invalid secret or signature'],
        WebhookService::CALL_DISABLED => [StatusCode::FORBIDDEN, 'Webhook disabled'],
        WebhookService::CALL_INVALID => [StatusCode::UNPROCESSABLE_CONTENT, 'The body must be empty or a JSON object of 32 KiB at most'],
    ];

    public function __construct(
        protected Authentication $authentication,
        protected WebhookService $webhooks,
        protected HistoryService $history,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/webhooks', [self::class, 'list']),
            Route::get('/webhooks/refused-calls', [self::class, 'refusedCalls']),
            Route::post('/webhooks', [self::class, 'create']),
            Route::put('/webhooks/{int:webhook}', [self::class, 'update']),
            Route::delete('/webhooks/{int:webhook}', [self::class, 'delete']),
            Route::post('/webhooks/{int:webhook}/secret', [self::class, 'regenerateSecret']),
            Route::get('/webhooks/{int:webhook}/calls', [self::class, 'calls']),
        ]);

        // Called by external services : authenticated by the secret of the webhook, not by a session
        $router->addRoutes(
            Route::post('/api/hooks/{uuid:uuid}', [self::class, 'receive']),
        );
    }

    #[Endpoint('List webhooks', 'The webhooks of the current workspace, oldest first', 'listWebhooks')]
    #[ResourceResponse(WebhookResource::class, isArray: true, description: 'The webhooks')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function list(Request $request): Response
    {
        return Response::json(WebhookResource::of($this->webhooks->listFor(currentWorkspaceId())));
    }

    #[Endpoint('Create webhook', 'Creates an enabled webhook in the current workspace, secured unless `secured` is false : this choice cannot change. The secret is only given by this answer, null for an unsecured webhook', 'createWebhook')]
    #[ObjectResponse(['webhook' => WebhookResource::class, 'secret' => 'whsec_abcdef'], responseCode: StatusCode::CREATED, description: 'The webhook and its secret')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function create(CreateWebhookRequest $request): Response
    {
        $validated = $request->validated();
        $secured = $validated['secured'] ?? true;
        [$webhook, $secret] = $this->webhooks->create(currentWorkspaceId(), $validated['name'], $validated['events'], (bool) $secured);
        $this->history->log($webhook->workspace, 'webhook.created', ['name' => $webhook->name, 'secured' => $webhook->secured], user: $this->authentication->userId());

        return Response::json(['webhook' => WebhookResource::of($webhook), 'secret' => $secret], StatusCode::CREATED);
    }

    #[Endpoint('Update webhook', 'Changes the name, the triggered events and the state of a webhook of the current workspace', 'updateWebhook')]
    #[ResourceResponse(WebhookResource::class, description: 'The webhook')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Webhook not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such webhook in the current workspace')]
    public function update(UpdateWebhookRequest $request, int $webhook): Response
    {
        $validated = $request->validated();
        $found = $this->webhooks->find(currentWorkspaceId(), $webhook);
        if (!$found)
            return $this->notFound();

        $wasEnabled = (bool) $found->enabled;
        $enabled = (bool) $validated['enabled'];
        $this->webhooks->update($found, $validated['name'], $validated['events'], $enabled);

        if ($wasEnabled !== $enabled)
            $this->history->log($found->workspace, $enabled ? 'webhook.enabled' : 'webhook.disabled', ['name' => $found->name], user: $this->authentication->userId());

        return Response::json(WebhookResource::of($found));
    }

    #[Endpoint('Delete webhook', 'Deletes a webhook of the current workspace and the trace of its calls. The events it triggered stay in the history', 'deleteWebhook')]
    #[NoContentResponse('The webhook is deleted')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Webhook not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such webhook in the current workspace')]
    public function delete(Request $request, int $webhook): Response
    {
        $found = $this->webhooks->find(currentWorkspaceId(), $webhook);
        if (!$found)
            return $this->notFound();

        $this->webhooks->delete($found);
        $this->history->log($found->workspace, 'webhook.deleted', ['name' => $found->name], user: $this->authentication->userId());

        return Response::noContent();
    }

    #[Endpoint('Regenerate webhook secret', 'Gives a webhook of the current workspace a new secret : the previous one stops working at once', 'regenerateWebhookSecret')]
    #[ObjectResponse(['secret' => 'whsec_abcdef'], description: 'The new secret, only given by this answer')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Webhook not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such webhook in the current workspace')]
    #[RawResponse(['error' => 'Unsecured webhook'], responseCode: StatusCode::CONFLICT, description: 'The webhook was created without a secret')]
    public function regenerateSecret(Request $request, int $webhook): Response
    {
        $found = $this->webhooks->find(currentWorkspaceId(), $webhook);
        if (!$found)
            return $this->notFound();

        if (!$found->secured)
            return Response::json(['error' => 'Unsecured webhook'], StatusCode::CONFLICT);

        $secret = $this->webhooks->regenerateSecret($found);
        $this->history->log($found->workspace, 'webhook.secret_regenerated', ['name' => $found->name], user: $this->authentication->userId());

        return Response::json(['secret' => $secret]);
    }

    #[Endpoint('List webhook calls', 'The calls received by a webhook of the current workspace, newest first, by pages of 50', 'listWebhookCalls')]
    #[ResourceResponse(WebhookCallResource::class, isArray: true, description: 'The calls older than `before`, or the latest ones')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Webhook not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such webhook in the current workspace')]
    public function calls(HistoryRequest $request, int $webhook): Response
    {
        $found = $this->webhooks->find(currentWorkspaceId(), $webhook);
        if (!$found)
            return $this->notFound();

        return Response::json(WebhookCallResource::of($this->webhooks->calls($found, $request->validated('before'))));
    }

    #[Endpoint('List refused webhook calls', 'The last calls refused by the webhooks of the current workspace, newest first', 'listRefusedWebhookCalls')]
    #[ResourceResponse(RefusedWebhookCallResource::class, isArray: true, description: 'The last 15 refused calls')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function refusedCalls(Request $request): Response
    {
        return Response::json(RefusedWebhookCallResource::of($this->webhooks->refusedCalls(currentWorkspaceId(), self::RECENT_LIMIT)));
    }

    #[Endpoint('Call webhook', 'Triggers the events of a webhook, with the body as payload. Authenticated by the secret in X-Tower-Secret, or by a signature of the body in X-Hub-Signature-256 or X-Hub-Signature (sha256=<HMAC-SHA256>). An unsecured webhook needs neither', 'callWebhook')]
    #[NoContentResponse('The events are triggered')]
    #[RawResponse(['error' => 'Invalid secret or signature'], responseCode: StatusCode::UNAUTHORIZED, description: 'Missing or wrong secret or signature')]
    #[RawResponse(['error' => 'Webhook disabled'], responseCode: StatusCode::FORBIDDEN, description: 'The webhook is disabled')]
    #[RawResponse(['error' => 'Webhook not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such webhook')]
    #[RawResponse(['error' => 'The body must be empty or a JSON object of 32 KiB at most'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'Invalid body')]
    public function receive(Request $request, string $uuid): Response
    {
        $webhook = $this->webhooks->findByUuid($uuid);
        if (!$webhook)
            return $this->notFound();

        [$status, $error] = self::CALL_RESPONSES[$this->webhooks->receive($webhook, $request)->status];

        return null === $error ? new Response($status) : Response::json(['error' => $error], $status);
    }

    private function notFound(): Response
    {
        return Response::json(['error' => 'Webhook not found'], StatusCode::NOT_FOUND);
    }
}
