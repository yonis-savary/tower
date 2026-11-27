<?php

namespace App\Controllers;

use App\Domain\Notification\PushService;
use App\Middlewares\IsAuthenticated;
use App\Requests\NotificationPreviewRequest;
use App\Requests\NotificationRuleRequest;
use App\Requests\PushClaimRequest;
use App\Requests\PushSubscriptionRequest;
use App\Requests\PushTestRequest;
use App\Requests\UpdateNotificationRuleRequest;
use App\Resources\NotificationRuleResource;
use App\Resources\PushSubscriptionResource;
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

use function App\currentWorkspace;
use function App\currentWorkspaceId;

/**
 * Push notifications : the browsers of the user and their rules, both in the current workspace.
 * Each member only sees and changes their own.
 */
class NotificationController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected PushService $push,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/push', [self::class, 'configuration']),
            Route::get('/push/subscriptions', [self::class, 'listSubscriptions']),
            Route::put('/push/subscriptions', [self::class, 'saveSubscription']),
            Route::post('/push/subscriptions/claim', [self::class, 'claimBrowser']),
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
    public function configuration(Request $request): Response
    {
        return Response::json(['enabled' => $this->push->isEnabled(), 'public_key' => $this->push->publicKey()]);
    }

    #[Endpoint('List push subscriptions', 'The browsers where the user enabled the push notifications of the current workspace, oldest first', 'listPushSubscriptions')]
    #[ResourceResponse(PushSubscriptionResource::class, isArray: true, description: 'The subscriptions')]
    public function listSubscriptions(Request $request): Response
    {
        return Response::json(PushSubscriptionResource::of($this->push->subscriptionsOf(currentWorkspaceId(), $this->authentication->userId())));
    }

    #[Endpoint('Save push subscription', 'Enables the push notifications of the current workspace on this browser, or updates its subscription there. What another user enabled on this browser is dropped', 'savePushSubscription')]
    #[ResourceResponse(PushSubscriptionResource::class, description: 'The subscription')]
    #[RawResponse(['error' => 'Push notifications are not set up'], responseCode: StatusCode::CONFLICT, description: 'No VAPID keys on this server')]
    public function saveSubscription(PushSubscriptionRequest $request): Response
    {
        $validated = $request->validated();
        if (!$this->push->isEnabled())
            return Response::json(['error' => 'Push notifications are not set up'], StatusCode::CONFLICT);

        $subscription = $this->push->saveSubscription(
            currentWorkspaceId(),
            $this->authentication->userId(),
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['content_encoding'],
            $request->getHeader('User-Agent'),
        );

        return Response::json(PushSubscriptionResource::of($subscription));
    }

    #[Endpoint('Claim browser', 'Sent each time Tower opens : drops what another user enabled on this browser, which now belongs to the user logged in', 'claimPushBrowser')]
    #[NoContentResponse('The browser belongs to the user')]
    public function claimBrowser(PushClaimRequest $request): Response
    {
        $this->push->claimBrowser($this->authentication->userId(), $request->validated('endpoint'));

        return Response::noContent();
    }

    #[Endpoint('Delete push subscription', 'Stops the push notifications of the current workspace on one browser of the user', 'deletePushSubscription')]
    #[NoContentResponse('The subscription is deleted')]
    #[RawResponse(['error' => 'Subscription not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such subscription of the user in the current workspace')]
    public function deleteSubscription(Request $request, int $subscription): Response
    {
        $found = $this->push->findSubscription(currentWorkspaceId(), $this->authentication->userId(), $subscription);
        if (!$found)
            return $this->subscriptionNotFound();

        $this->push->deleteSubscription($found);

        return Response::noContent();
    }

    #[Endpoint('Test push subscription', 'Sends a notification to one browser of the user. A browser that dropped its subscription is deleted', 'testPushSubscription')]
    #[NoContentResponse('The browser accepted the notification')]
    #[RawResponse(['error' => 'Subscription not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such subscription of the user in the current workspace')]
    #[RawResponse(['error' => 'The push service refused the notification'], responseCode: StatusCode::BAD_GATEWAY, description: 'The push service of the browser refused it')]
    public function testSubscription(PushTestRequest $request, int $subscription): Response
    {
        $validated = $request->validated();
        $found = $this->push->findSubscription(currentWorkspaceId(), $this->authentication->userId(), $subscription);
        if (!$found)
            return $this->subscriptionNotFound();

        if (!$this->push->isEnabled() || !$this->push->sendTest($found, $validated['title'], (string) $validated['body']))
            return Response::json(['error' => 'The push service refused the notification'], StatusCode::BAD_GATEWAY);

        return Response::noContent();
    }

    #[Endpoint('List notification rules', 'The notification rules of the user in the current workspace, oldest first', 'listNotificationRules')]
    #[ResourceResponse(NotificationRuleResource::class, isArray: true, description: 'The rules')]
    public function listRules(Request $request): Response
    {
        return Response::json(NotificationRuleResource::of($this->push->rulesOf(currentWorkspaceId(), $this->authentication->userId())));
    }

    #[Endpoint('Create notification rule', 'Notifies the browsers of the user of the events of the current workspace matching `listen`. `title` and `body` are each a raw value or a key of the payload, null for the default text', 'createNotificationRule')]
    #[ResourceResponse(NotificationRuleResource::class, responseCode: StatusCode::CREATED, description: 'The rule')]
    public function createRule(NotificationRuleRequest $request): Response
    {
        $validated = $request->validated();
        $rule = $this->push->createRule(
            currentWorkspaceId(),
            $this->authentication->userId(),
            $validated['name'],
            $validated['listen'],
            $validated['title'] ?? null,
            $validated['body'] ?? null,
        );

        return Response::json(NotificationRuleResource::of($rule), StatusCode::CREATED);
    }

    #[Endpoint('Preview notification', 'The title and the body a rule would give for this event', 'previewNotification')]
    #[RawResponse(['title' => 'Pipeline failed', 'body' => 'Fix the login form'], description: 'The text of the notification')]
    public function previewRule(NotificationPreviewRequest $request): Response
    {
        $validated = $request->validated();
        return Response::json($this->push->content(
            $validated['title'] ?? null,
            $validated['body'] ?? null,
            currentWorkspace()->name,
            $validated['type'],
            $validated['payload'],
        ));
    }

    #[Endpoint('Update notification rule', 'Changes the events, the text and the state of a rule of the user in the current workspace', 'updateNotificationRule')]
    #[ResourceResponse(NotificationRuleResource::class, description: 'The rule')]
    #[RawResponse(['error' => 'Notification rule not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such rule of the user in the current workspace')]
    public function updateRule(UpdateNotificationRuleRequest $request, int $rule): Response
    {
        $validated = $request->validated();
        $found = $this->push->findRule(currentWorkspaceId(), $this->authentication->userId(), $rule);
        if (!$found)
            return $this->ruleNotFound();

        $this->push->updateRule(
            $found,
            $validated['name'],
            $validated['listen'],
            $validated['title'] ?? null,
            $validated['body'] ?? null,
            (bool) $validated['enabled'],
        );

        return Response::json(NotificationRuleResource::of($found));
    }

    #[Endpoint('Delete notification rule', 'Deletes a rule of the user in the current workspace', 'deleteNotificationRule')]
    #[NoContentResponse('The rule is deleted')]
    #[RawResponse(['error' => 'Notification rule not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such rule of the user in the current workspace')]
    public function deleteRule(Request $request, int $rule): Response
    {
        $found = $this->push->findRule(currentWorkspaceId(), $this->authentication->userId(), $rule);
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
}
