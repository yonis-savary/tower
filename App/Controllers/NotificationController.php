<?php

namespace App\Controllers;

use App\Middlewares\IsAuthenticated;
use App\Requests\NotificationPreviewRequest;
use App\Requests\NotificationRuleRequest;
use App\Requests\PushSubscriptionRequest;
use App\Requests\PushTestRequest;
use App\Requests\UpdateNotificationRuleRequest;
use App\Resources\NotificationRuleResource;
use App\Resources\PushSubscriptionResource;
use App\Services\PushService;
use App\Services\WorkspaceService;
use Cube\Data\OpenAPI\Attributes\Endpoint;
use Cube\Data\OpenAPI\Attributes\NoContentResponse;
use Cube\Data\OpenAPI\Attributes\RawResponse;
use Cube\Data\OpenAPI\Attributes\ResourceResponse;
use Cube\Security\Authentication;
use Cube\Web\Controller;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Http\StatusCode;
use Cube\Web\Router\Route;
use Cube\Web\Router\Router;

/**
 * Push notifications : the browsers of the user, and their rules in the current workspace. Each
 * member only sees and changes their own.
 */
class NotificationController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected WorkspaceService $workspaces,
        protected PushService $push,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/push', [self::class, 'configuration']),
            Route::get('/push/subscriptions', [self::class, 'listSubscriptions']),
            Route::put('/push/subscriptions', [self::class, 'saveSubscription']),
            Route::delete('/push/subscriptions/{int:subscription}', [self::class, 'deleteSubscription']),
            Route::post('/push/subscriptions/{int:subscription}/test', [self::class, 'testSubscription']),
            Route::get('/notification-rules', [self::class, 'listRules']),
            Route::post('/notification-rules', [self::class, 'createRule']),
            Route::post('/notification-rules/preview', [self::class, 'previewRule']),
            Route::put('/notification-rules/{int:rule}', [self::class, 'updateRule']),
            Route::delete('/notification-rules/{int:rule}', [self::class, 'deleteRule']),
        ]);
    }

    #[Endpoint('Push configuration', 'Whether this server can send push notifications, and the public VAPID key a browser subscribes with', 'getPushConfiguration')]
    #[RawResponse(['enabled' => true, 'public_key' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U'], description: 'public_key is null when push notifications are not set up')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function configuration(Request $request): Response
    {
        return Response::json(['enabled' => $this->push->isEnabled(), 'public_key' => $this->push->publicKey()]);
    }

    #[Endpoint('List push subscriptions', 'The browsers where the user enabled push notifications, oldest first', 'listPushSubscriptions')]
    #[ResourceResponse(PushSubscriptionResource::class, isArray: true, description: 'The subscriptions')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function listSubscriptions(Request $request): Response
    {
        return Response::json(PushSubscriptionResource::of($this->push->subscriptionsOf($this->authentication->userId())));
    }

    #[Endpoint('Save push subscription', 'Records the subscription of this browser for the user, or updates it when its endpoint is known', 'savePushSubscription')]
    #[ResourceResponse(PushSubscriptionResource::class, description: 'The subscription')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Push notifications are not set up'], responseCode: StatusCode::CONFLICT, description: 'No VAPID keys on this server')]
    public function saveSubscription(PushSubscriptionRequest $request): Response
    {
        if (!$this->push->isEnabled())
            return Response::json(['error' => 'Push notifications are not set up'], StatusCode::CONFLICT);

        $subscription = $this->push->saveSubscription(
            $this->authentication->userId(),
            $request->validated('endpoint'),
            $request->validated('keys')['p256dh'],
            $request->validated('keys')['auth'],
            $request->validated('content_encoding'),
            $request->getHeader('User-Agent'),
        );

        return Response::json(PushSubscriptionResource::of($subscription));
    }

    #[Endpoint('Delete push subscription', 'Stops the push notifications on one browser of the user', 'deletePushSubscription')]
    #[NoContentResponse('The subscription is deleted')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Subscription not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such subscription for this user')]
    public function deleteSubscription(Request $request, int $subscription): Response
    {
        $found = $this->push->findSubscription($this->authentication->userId(), $subscription);
        if (!$found)
            return $this->subscriptionNotFound();

        $this->push->deleteSubscription($found);

        return Response::noContent();
    }

    #[Endpoint('Test push subscription', 'Sends a notification to one browser of the user. A browser that dropped its subscription is deleted', 'testPushSubscription')]
    #[NoContentResponse('The browser accepted the notification')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Subscription not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such subscription for this user')]
    #[RawResponse(['error' => 'The push service refused the notification'], responseCode: StatusCode::BAD_GATEWAY, description: 'The push service of the browser refused it')]
    public function testSubscription(PushTestRequest $request, int $subscription): Response
    {
        $found = $this->push->findSubscription($this->authentication->userId(), $subscription);
        if (!$found)
            return $this->subscriptionNotFound();

        if (!$this->push->isEnabled() || !$this->push->sendTest($found, $request->validated('title'), (string) $request->validated('body')))
            return Response::json(['error' => 'The push service refused the notification'], StatusCode::BAD_GATEWAY);

        return Response::noContent();
    }

    #[Endpoint('List notification rules', 'The notification rules of the user in the current workspace, oldest first', 'listNotificationRules')]
    #[ResourceResponse(NotificationRuleResource::class, isArray: true, description: 'The rules')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function listRules(Request $request): Response
    {
        return Response::json(NotificationRuleResource::of($this->push->rulesOf($this->currentWorkspaceId(), $this->authentication->userId())));
    }

    #[Endpoint('Create notification rule', 'Notifies the browsers of the user of the events of the current workspace matching `listen`. `title` and `body` are each a raw value or a key of the payload, null for the default text', 'createNotificationRule')]
    #[ResourceResponse(NotificationRuleResource::class, responseCode: StatusCode::CREATED, description: 'The rule')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function createRule(NotificationRuleRequest $request): Response
    {
        $rule = $this->push->createRule(
            $this->currentWorkspaceId(),
            $this->authentication->userId(),
            $request->validated('name'),
            $request->validated('listen'),
            $request->validated('title'),
            $request->validated('body'),
        );

        return Response::json(NotificationRuleResource::of($rule), StatusCode::CREATED);
    }

    #[Endpoint('Preview notification', 'The title and the body a rule would give for this event', 'previewNotification')]
    #[RawResponse(['title' => 'Pipeline failed', 'body' => 'Fix the login form'], description: 'The text of the notification')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function previewRule(NotificationPreviewRequest $request): Response
    {
        return Response::json($this->push->content(
            $request->validated('title'),
            $request->validated('body'),
            $this->workspaces->current($this->authentication->userId())->name,
            $request->validated('type'),
            $request->validated('payload'),
        ));
    }

    #[Endpoint('Update notification rule', 'Changes the events, the text and the state of a rule of the user in the current workspace', 'updateNotificationRule')]
    #[ResourceResponse(NotificationRuleResource::class, description: 'The rule')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Notification rule not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such rule of the user in the current workspace')]
    public function updateRule(UpdateNotificationRuleRequest $request, int $rule): Response
    {
        $found = $this->push->findRule($this->currentWorkspaceId(), $this->authentication->userId(), $rule);
        if (!$found)
            return $this->ruleNotFound();

        $this->push->updateRule(
            $found,
            $request->validated('name'),
            $request->validated('listen'),
            $request->validated('title'),
            $request->validated('body'),
            (bool) $request->validated('enabled'),
        );

        return Response::json(NotificationRuleResource::of($found));
    }

    #[Endpoint('Delete notification rule', 'Deletes a rule of the user in the current workspace', 'deleteNotificationRule')]
    #[NoContentResponse('The rule is deleted')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Notification rule not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such rule of the user in the current workspace')]
    public function deleteRule(Request $request, int $rule): Response
    {
        $found = $this->push->findRule($this->currentWorkspaceId(), $this->authentication->userId(), $rule);
        if (!$found)
            return $this->ruleNotFound();

        $this->push->deleteRule($found);

        return Response::noContent();
    }

    private function subscriptionNotFound(): Response
    {
        return Response::json(['error' => 'Subscription not found'], StatusCode::NOT_FOUND);
    }

    private function ruleNotFound(): Response
    {
        return Response::json(['error' => 'Notification rule not found'], StatusCode::NOT_FOUND);
    }

    private function currentWorkspaceId(): int
    {
        return $this->workspaces->current($this->authentication->userId())->id;
    }
}
