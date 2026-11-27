<?php

namespace Tests\Integration;

use App\Domain\Notification\PushService;
use App\Models\NotificationRule;
use App\Models\PushSubscription;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Push\PushConfiguration;
use App\Push\PushResult;
use App\Push\PushSender;
use App\Queues\PushQueue;
use App\Session\SelectedWorkspace;
use Cube\Env\Configuration;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;
use Cube\Web\Http\Request;
use Cube\Web\Websocket\Broadcast\Broadcast;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;

class NotificationTest extends CubeTestCase
{
    private const PASSWORD = 'correct horse battery';

    private Broadcast $originalBroadcast;
    private PushSender $originalSender;
    private object $sender;

    protected function setUp(): void
    {
        parent::setUp();
        Authentication::getInstance()->logout();
        SelectedWorkspace::unset();

        // Never reach the real websocket server
        $this->originalBroadcast = Broadcast::getInstance();
        Broadcast::setInstance(new class(new BroadcastConfiguration()) extends Broadcast {
            public function emit(string $event, array $data): bool
            {
                return true;
            }
        });

        // Nor a real push service : each send answers the next status given, 201 when there is none
        $this->originalSender = PushSender::getInstance();
        $this->sender = new class(new PushConfiguration()) extends PushSender {
            public array $sent = [];
            public array $statuses = [];
            public array $waits = [];

            public function send(PushSubscription $subscription, array $message): PushResult
            {
                $this->sent[] = ['endpoint' => $subscription->endpoint, ...$message];

                return new PushResult(array_shift($this->statuses) ?? 201);
            }

            public function wait(int $seconds): void
            {
                $this->waits[] = $seconds;
            }
        };
        PushSender::setInstance($this->sender);

        $this->configurePush(enabled: true);
        inject(PushQueue::class)->flush();
    }

    protected function tearDown(): void
    {
        Broadcast::setInstance($this->originalBroadcast);
        PushSender::setInstance($this->originalSender);
        $this->configurePush(enabled: false);
        parent::tearDown();
    }

    private function configurePush(bool $enabled): void
    {
        Configuration::getInstance()->add($enabled
            ? new PushConfiguration('public-key', 'private-key', 'mailto:admin@example.com')
            : new PushConfiguration());
    }

    private function register(string $email): int
    {
        return $this->postJson('/api/auth/register', ['email' => $email, 'password' => self::PASSWORD])->assertCreated()->json()['id'];
    }

    private function logOut(): void
    {
        $this->post('/api/auth/logout')->assertNoContent();
    }

    private function logIn(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => self::PASSWORD])->assertOk();
    }

    private function subscribe(string $endpoint = 'https://push.example.com/abc'): array
    {
        return $this->putJson('/api/push/subscriptions', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'],
            'content_encoding' => 'aes128gcm',
        ])->assertOk()->json();
    }

    private function createRule(array $values = []): array
    {
        return $this->postJson('/api/notification-rules', ['name' => 'Pipelines', 'listen' => ['pr.*'], ...$values])->assertCreated()->json();
    }

    private function trigger(string $type, array $payload = []): void
    {
        $this->postJson('/api/events', ['type' => $type, 'payload' => json_encode((object) $payload)])->assertNoContent();
    }

    private function processQueue(): void
    {
        $queue = inject(PushQueue::class);
        while ($queue->processNext());
    }

    private function sentTitles(): array
    {
        return array_column($this->sender->sent, 'title');
    }

    public function testRoutesRequireAuthentication()
    {
        $this->get('/api/push')->assertUnauthorized();
        $this->get('/api/push/subscriptions')->assertUnauthorized();
        $this->postJson('/api/push/subscriptions/claim', ['endpoint' => 'https://push.example.com/abc'])->assertUnauthorized();
        $this->get('/api/notification-rules')->assertUnauthorized();
        $this->postJson('/api/notification-rules', ['name' => 'a', 'listen' => ['*']])->assertUnauthorized();
    }

    public function testConfigurationGivesThePublicKeyOnlyWhenSetUp()
    {
        $this->register('alice@example.com');
        $this->assertSame(['enabled' => true, 'public_key' => 'public-key'], $this->get('/api/push')->assertOk()->json());

        $this->configurePush(enabled: false);
        $this->assertSame(['enabled' => false, 'public_key' => null], $this->get('/api/push')->assertOk()->json());
        $this->putJson('/api/push/subscriptions', ['endpoint' => 'https://push.example.com/abc', 'keys' => ['p256dh' => 'abc', 'auth' => 'abc'], 'content_encoding' => 'aes128gcm'])->assertResponseCode(409);
    }

    public function testSubscriptionIsUpdatedRatherThanDuplicated()
    {
        $this->register('alice@example.com');
        $first = $this->subscribe();
        $again = $this->subscribe();

        $this->assertSame($first['id'], $again['id']);
        $this->assertSame('https://push.example.com/abc', $again['endpoint']);
        $this->assertCount(1, $this->get('/api/push/subscriptions')->json());

        $this->putJson('/api/push/subscriptions', ['endpoint' => 'http://push.example.com/abc', 'keys' => ['p256dh' => 'abc', 'auth' => 'abc'], 'content_encoding' => 'aes128gcm'])->assertUnprocessableContent();
        $this->putJson('/api/push/subscriptions', ['endpoint' => 'https://push.example.com/abc', 'keys' => ['p256dh' => 'abc', 'auth' => 'abc'], 'content_encoding' => 'gzip'])->assertUnprocessableContent();
    }

    public function testSubscriptionBelongsToTheLastUserOfTheBrowser()
    {
        $this->register('bob@example.com');
        $this->subscribe();
        $this->logOut();

        $this->register('alice@example.com');
        $this->subscribe();
        $this->assertCount(1, $this->get('/api/push/subscriptions')->json());

        $this->logOut();
        $this->logIn('bob@example.com');
        $this->assertCount(0, $this->get('/api/push/subscriptions')->json());
    }

    public function testSubscriptionsOfSomeoneElseCannotBeTouched()
    {
        $this->register('bob@example.com');
        $bobs = $this->subscribe('https://push.example.com/bob');
        $this->logOut();

        $this->register('alice@example.com');
        $this->assertSame([], $this->get('/api/push/subscriptions')->json());
        $this->delete("/api/push/subscriptions/{$bobs['id']}")->assertNotFound();
        $this->postJson("/api/push/subscriptions/{$bobs['id']}/test", ['title' => 'Tower', 'body' => 'Test'])->assertNotFound();
        $this->assertNotNull(PushSubscription::find($bobs['id']));
    }

    public function testBrowserIsEnabledPerWorkspace()
    {
        $this->register('alice@example.com');
        $pro = $this->get('/api/workspaces/current')->json()['id'];
        $perso = $this->postJson('/api/workspaces', ['name' => 'Perso'])->assertCreated()->json()['id'];

        $this->subscribe('https://push.example.com/laptop');
        $this->createRule(['listen' => ['*']]);
        $this->putJson('/api/workspaces/current', ['workspace' => $perso])->assertOk();
        $this->assertSame([], $this->get('/api/push/subscriptions')->json());
        $this->subscribe('https://push.example.com/phone');
        $this->createRule(['listen' => ['*']]);

        $this->trigger('perso.event');
        $this->putJson('/api/workspaces/current', ['workspace' => $pro])->assertOk();
        $this->trigger('pro.event');
        $this->processQueue();

        $this->assertSame([
            ['https://push.example.com/phone', 'Perso · perso.event'],
            ['https://push.example.com/laptop', Workspace::find($pro)->name.' · pro.event'],
        ], array_map(fn ($sent) => [$sent['endpoint'], $sent['title']], $this->sender->sent));
    }

    public function testSameBrowserInSeveralWorkspaces()
    {
        $this->register('alice@example.com');
        $first = $this->subscribe();
        $perso = $this->postJson('/api/workspaces', ['name' => 'Perso'])->assertCreated()->json()['id'];
        $this->putJson('/api/workspaces/current', ['workspace' => $perso])->assertOk();
        $second = $this->subscribe();

        $this->assertNotSame($first['id'], $second['id']);
        $this->delete("/api/push/subscriptions/{$first['id']}")->assertNotFound();
        $this->delete("/api/push/subscriptions/{$second['id']}")->assertNoContent();
        $this->assertNotNull(PushSubscription::find($first['id']));
    }

    public function testClaimDropsWhatAnotherUserEnabledOnTheBrowser()
    {
        $this->register('bob@example.com');
        $bobs = $this->subscribe();
        $this->logOut();

        $this->register('alice@example.com');
        $this->postJson('/api/push/subscriptions/claim', ['endpoint' => 'https://push.example.com/other'])->assertNoContent();
        $this->assertNotNull(PushSubscription::find($bobs['id']));

        $this->postJson('/api/push/subscriptions/claim', ['endpoint' => 'https://push.example.com/abc'])->assertNoContent();
        $this->assertNull(PushSubscription::find($bobs['id']));
        $this->postJson('/api/push/subscriptions/claim', ['endpoint' => 'http://push.example.com/abc'])->assertUnprocessableContent();
    }

    public function testTestNotificationAndDeletion()
    {
        $this->register('alice@example.com');
        $subscription = $this->subscribe();

        $this->postJson("/api/push/subscriptions/{$subscription['id']}/test", ['title' => 'Tower', 'body' => 'It works'])->assertNoContent();
        $this->assertSame('It works', $this->sender->sent[0]['body']);
        $this->assertNotNull($this->get('/api/push/subscriptions')->json()[0]['last_success_at']);

        $this->delete("/api/push/subscriptions/{$subscription['id']}")->assertNoContent();
        $this->assertSame([], $this->get('/api/push/subscriptions')->json());
    }

    public function testANotificationShowsTheUploadedIconOfItsWorkspace()
    {
        $this->register('alice@example.com');
        $workspace = SelectedWorkspace::get();
        $subscription = $this->subscribe();

        $this->postJson("/api/push/subscriptions/{$subscription['id']}/test", ['title' => 'Tower', 'body' => 'Without icon'])->assertNoContent();
        $this->assertArrayNotHasKey('icon', $this->sender->sent[0]);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $this->assertResponseOf(new Request('PUT', "/api/workspaces/{$workspace}/icon", [], [], ['content-type' => 'image/png'], body: $png))->assertOk();
        $path = '/api/workspace-icons/'.Workspace::find($workspace)->icon;

        $this->postJson("/api/push/subscriptions/{$subscription['id']}/test", ['title' => 'Tower', 'body' => 'With icon'])->assertNoContent();
        $this->assertSame($path, $this->sender->sent[1]['icon']);

        $this->createRule();
        $this->trigger('pr.opened');
        $this->processQueue();
        $this->assertSame($path, end($this->sender->sent)['icon']);
    }

    public function testTestOnAGoneSubscriptionDeletesIt()
    {
        $this->register('alice@example.com');
        $subscription = $this->subscribe();

        $this->sender->statuses = [410];
        $this->postJson("/api/push/subscriptions/{$subscription['id']}/test", ['title' => 'Tower', 'body' => ''])->assertResponseCode(502);
        $this->assertNull(PushSubscription::find($subscription['id']));
    }

    public function testRuleLifecycle()
    {
        $this->register('alice@example.com');
        $rule = $this->createRule([
            'title' => '  Pipeline {payload.status}  ',
            'body' => '{payload.title}',
        ]);

        $this->assertSame('Pipelines', $rule['name']);
        $this->assertSame(['pr.*'], $rule['listen']);
        $this->assertSame('Pipeline {payload.status}', $rule['title']);
        $this->assertSame('{payload.title}', $rule['body']);
        $this->assertTrue($rule['enabled']);

        $updated = $this->putJson("/api/notification-rules/{$rule['id']}", ['name' => 'Tasks', 'listen' => ['task.*'], 'title' => null, 'body' => '   ', 'enabled' => false])->assertOk()->json();
        $this->assertSame('Tasks', $updated['name']);
        $this->assertSame(['task.*'], $updated['listen']);
        $this->assertNull($updated['title']);
        $this->assertNull($updated['body']);
        $this->assertFalse($updated['enabled']);
        $this->assertCount(1, $this->get('/api/notification-rules')->json());

        $this->delete("/api/notification-rules/{$rule['id']}")->assertNoContent();
        $this->assertSame([], $this->get('/api/notification-rules')->json());
    }

    public function testRuleValidation()
    {
        $this->register('alice@example.com');

        $this->postJson('/api/notification-rules', ['name' => 'Empty', 'listen' => []])->assertUnprocessableContent();
        $this->postJson('/api/notification-rules', ['name' => 'Bad pattern', 'listen' => ['Task Created']])->assertUnprocessableContent();
        $this->postJson('/api/notification-rules', ['name' => '', 'listen' => ['*']])->assertUnprocessableContent();
        $this->postJson('/api/notification-rules', ['name' => 'Long', 'listen' => ['*'], 'title' => str_repeat('a', PushService::MAX_TEMPLATE_LENGTH + 1)])->assertUnprocessableContent();
        $this->postJson('/api/notification-rules', ['name' => 'Not text', 'listen' => ['*'], 'body' => ['mode' => 'raw', 'value' => 'x']])->assertUnprocessableContent();
    }

    public function testRulesArePersonal()
    {
        $bob = $this->register('bob@example.com');
        $rule = $this->createRule();
        $this->logOut();

        $alice = $this->register('alice@example.com');
        // Alice joins Bob's workspace : his rules stay his
        $bobWorkspace = Workspace::findWhere(['author' => $bob])->id;
        WorkspaceMember::fromArray(['workspace' => $bobWorkspace, 'user' => $alice])->save();
        $this->putJson('/api/workspaces/current', ['workspace' => $bobWorkspace])->assertOk();

        $this->assertSame([], $this->get('/api/notification-rules')->json());
        $this->putJson("/api/notification-rules/{$rule['id']}", ['name' => 'Mine', 'listen' => ['*'], 'enabled' => true])->assertNotFound();
        $this->delete("/api/notification-rules/{$rule['id']}")->assertNotFound();
    }

    public function testPreview()
    {
        $this->register('alice@example.com');
        $workspace = $this->get('/api/workspaces/current')->json()['name'];

        $preview = $this->postJson('/api/notification-rules/preview', [
            'title' => '{payload.author.name} on {workspace}',
            'body' => 'Pipeline failed ({event})',
            'type' => 'pr.pipeline.failed',
            'payload' => '{"author":{"name":"Alice"}}',
        ])->assertOk()->json();
        $this->assertSame(['title' => "Alice on {$workspace}", 'body' => 'Pipeline failed (pr.pipeline.failed)'], $preview);

        $default = $this->postJson('/api/notification-rules/preview', ['type' => 'deploy.done', 'payload' => '{"env":"prod","ok":true}'])->assertOk()->json();
        $this->assertSame(['title' => "{$workspace} · deploy.done", 'body' => 'env: prod, ok: true'], $default);
    }

    public function testContentResolution()
    {
        $push = inject(PushService::class);
        $payload = json_encode(['title' => 'Fix login', 'author' => ['name' => 'Alice', 'roles' => ['dev']], 'count' => 3, 'empty' => '']);

        $content = fn (?string $title, ?string $body = null) => $push->content($title, $body, 'Pro', 'task.created', $payload);

        $this->assertSame(['title' => 'Pro · task.created', 'body' => 'Fix login'], $content(null, null));
        $this->assertSame('3 new tasks by Alice', $content('{payload.count} new tasks by {payload.author.name}')['title']);
        $this->assertSame('{"name":"Alice","roles":["dev"]}', $content('{payload.author}')['title']);
        $this->assertSame('task.created in Pro', $content('{event} in {workspace}')['title']);
        $this->assertSame('author.name', $content('author.name')['title']);
        // Like the connector handlers : a placeholder without a value stays as written
        $this->assertSame('By {payload.author.email} {unknown}', $content('By {payload.author.email} {unknown}')['title']);
        $this->assertSame('{ not a placeholder }', $content('{ not a placeholder }')['title']);
        // A template that renders empty : the default text
        $this->assertSame('Fix login', $content(null, '{payload.empty}')['body']);

        $long = $push->content(str_repeat('a', 150), null, 'Pro', 'x', '{}');
        $this->assertSame(100, mb_strlen($long['title']));
        $this->assertStringEndsWith('…', $long['title']);
    }

    public function testMatchingEventIsSentToEveryBrowserOfTheUser()
    {
        $this->register('alice@example.com');
        $workspace = $this->get('/api/workspaces/current')->json()['id'];
        $this->subscribe('https://push.example.com/phone');
        $this->subscribe('https://push.example.com/laptop');
        $rule = $this->createRule(['listen' => ['deploy.*'], 'title' => 'Deployed', 'body' => '{payload.env}']);

        $this->trigger('deploy.done', ['env' => 'prod']);
        $this->trigger('task.reminder', ['title' => 'Ignored']);
        $this->processQueue();

        $this->assertSame(['https://push.example.com/phone', 'https://push.example.com/laptop'], array_column($this->sender->sent, 'endpoint'));
        $sent = $this->sender->sent[0];
        $this->assertSame('Deployed', $sent['title']);
        $this->assertSame('prod', $sent['body']);
        $this->assertSame("rule-{$rule['id']}-deploy.done", $sent['tag']);
        $this->assertMatchesRegularExpression("#^/history\\?workspace={$workspace}&line=\\d+$#", $sent['url']);
    }

    public function testOneNotificationPerMatchingRule()
    {
        $this->register('alice@example.com');
        $this->subscribe();
        $this->createRule(['name' => 'All', 'listen' => ['*'], 'title' => 'All']);
        $this->createRule(['name' => 'Deploys', 'listen' => ['deploy.*'], 'title' => 'Deploys']);
        $disabled = $this->createRule(['name' => 'Off', 'listen' => ['*'], 'title' => 'Off']);
        $this->putJson("/api/notification-rules/{$disabled['id']}", ['name' => 'Off', 'listen' => ['*'], 'enabled' => false])->assertOk();

        $this->trigger('deploy.done');
        $this->processQueue();

        $this->assertSame(['All', 'Deploys'], $this->sentTitles());
    }

    public function testNothingIsQueuedWithoutRuleOrKeys()
    {
        $this->register('alice@example.com');
        $this->subscribe();

        $this->trigger('deploy.done');
        $this->processQueue();
        $this->assertSame([], $this->sender->sent);

        $this->createRule(['listen' => ['*']]);
        $this->configurePush(enabled: false);
        $this->trigger('deploy.done');
        $this->configurePush(enabled: true);
        $this->processQueue();
        $this->assertSame([], $this->sender->sent);
    }

    public function testTaskEventsAreNotified()
    {
        $this->register('alice@example.com');
        $this->subscribe();
        $this->createRule(['listen' => ['task.created']]);

        $this->postJson('/api/tasks', ['title' => 'Write the docs'])->assertCreated();
        $this->processQueue();

        $this->assertSame('Write the docs', $this->sender->sent[0]['body']);
    }

    public function testGoneSubscriptionIsDeleted()
    {
        $this->register('alice@example.com');
        $subscription = $this->subscribe();
        $this->createRule(['listen' => ['*']]);

        $this->sender->statuses = [410];
        $this->trigger('deploy.done');
        $this->processQueue();

        $this->assertNull(PushSubscription::find($subscription['id']));
    }

    public function testThrottledNotificationIsRetried()
    {
        $this->register('alice@example.com');
        $subscription = $this->subscribe();
        $this->createRule(['listen' => ['*']]);

        $this->sender->statuses = [429, 503];
        $this->trigger('deploy.done');
        $this->processQueue();

        $this->assertCount(3, $this->sender->sent);
        $this->assertCount(2, $this->sender->waits);
        $this->assertNotNull(PushSubscription::find($subscription['id'])->last_success_at);
    }

    public function testRetriesStop()
    {
        $this->register('alice@example.com');
        $subscription = $this->subscribe();
        $this->createRule(['listen' => ['*']]);

        $this->sender->statuses = [500, 500, 500, 500];
        $this->trigger('deploy.done');
        $this->processQueue();

        $this->assertCount(PushService::MAX_ATTEMPTS, $this->sender->sent);
        $this->assertNotNull(PushSubscription::find($subscription['id']));
    }

    public function testFormerMemberIsNoLongerNotified()
    {
        $bob = $this->register('bob@example.com');
        $this->logOut();
        $alice = $this->register('alice@example.com');
        $aliceWorkspace = Workspace::findWhere(['author' => $alice])->id;
        $this->postJson("/api/workspaces/{$aliceWorkspace}/members", ['email' => 'bob@example.com'])->assertOk();
        $this->logOut();

        $this->logIn('bob@example.com');
        $this->putJson('/api/workspaces/current', ['workspace' => $aliceWorkspace])->assertOk();
        $this->subscribe();
        $this->createRule(['listen' => ['*']]);
        $this->logOut();

        $this->logIn('alice@example.com');
        $this->delete("/api/workspaces/{$aliceWorkspace}/members/{$bob}")->assertNoContent();
        $this->assertSame(0, NotificationRule::select()->where('user', $bob)->count());
        $this->assertSame(0, PushSubscription::select()->where('user', $bob)->count());

        $this->trigger('deploy.done');
        $this->processQueue();
        $this->assertSame([], $this->sender->sent);
    }
}
