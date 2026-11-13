<?php

namespace Tests\Integration;

use App\Models\History;
use App\Models\WebhookCall;
use App\Services\WorkspaceSignals;
use App\Session\SelectedWorkspace;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;
use Cube\Web\Http\Request;
use Cube\Web\Websocket\Broadcast\Broadcast;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;

class WebhookTest extends CubeTestCase
{
    private Broadcast $originalBroadcast;
    private object $broadcast;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logOut();

        // Never reach the real websocket server
        $this->originalBroadcast = Broadcast::getInstance();
        $this->broadcast = new class(new BroadcastConfiguration()) extends Broadcast {
            public array $emitted = [];

            public array $signals = [];

            public function emit(string $event, array $data): bool
            {
                // Signals to the browsers are kept apart from what the devices receive
                if (in_array($data['type'] ?? null, WorkspaceSignals::SIGNALS, true)) {
                    $this->signals[] = $data;
                    return true;
                }

                $this->emitted[] = $data;
                return true;
            }
        };
        Broadcast::setInstance($this->broadcast);
    }

    protected function tearDown(): void
    {
        Broadcast::setInstance($this->originalBroadcast);
        parent::tearDown();
    }

    private function logOut(): void
    {
        Authentication::getInstance()->logout();
        SelectedWorkspace::unset();
    }

    private function register(string $email): void
    {
        $this->postJson('/api/auth/register', ['email' => $email, 'password' => 'correct horse battery'])->assertCreated();
    }

    private function createWebhook(array $events = ['deploy.start', 'notify.me']): array
    {
        return $this->postJson('/api/webhooks', ['name' => 'CI', 'events' => $events])->assertCreated()->json();
    }

    /**
     * A call from an external service : raw body, no session.
     */
    private function call(string $uuid, string $body, array $headers = [])
    {
        return $this->assertResponseOf(new Request('POST', '/api/hooks/'.$uuid, [], [], ['content-type' => 'application/json', ...$headers], body: $body));
    }

    public function testManagementRequiresAuthentication()
    {
        $this->get('/api/webhooks')->assertUnauthorized();
        $this->postJson('/api/webhooks', ['name' => 'CI', 'events' => ['a.b']])->assertUnauthorized();
    }

    public function testCreationGivesTheSecretOnce()
    {
        $this->register('alice@example.com');
        $created = $this->createWebhook();

        $this->assertStringStartsWith('whsec_', $created['secret']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $created['webhook']['uuid']);
        $this->assertTrue($created['webhook']['enabled']);
        $this->assertSame(['deploy.start', 'notify.me'], $created['webhook']['events']);

        $listed = $this->get('/api/webhooks')->assertOk()->json();
        $this->assertCount(1, $listed);
        $this->assertArrayNotHasKey('secret', $listed[0]);
        $this->assertNotNull(History::findWhere(['type' => 'webhook.created']));
    }

    public function testInvalidWebhooksAreRefused()
    {
        $this->register('alice@example.com');

        foreach ([
            ['name' => '', 'events' => ['a.b']],
            ['name' => 'CI', 'events' => []],
            ['name' => 'CI', 'events' => ['Not Valid']],
            ['name' => 'CI', 'events' => ['a.*']],
            ['name' => 'CI', 'events' => array_map(fn ($i) => "e.$i", range(1, 21))],
        ] as $body) {
            $this->postJson('/api/webhooks', $body)->assertUnprocessableContent();
        }
    }

    public function testCallWithTheSecretTriggersEachEvent()
    {
        $this->register('alice@example.com');
        $created = $this->createWebhook();
        $this->logOut();

        $this->call($created['webhook']['uuid'], '{"ref":"main"}', ['X-Tower-Secret' => $created['secret']])->assertNoContent();

        $this->assertSame(['deploy.start', 'notify.me'], array_column($this->broadcast->emitted, 'event'));
        $this->assertSame('{"ref":"main"}', $this->broadcast->emitted[0]['payload']);

        $line = History::findWhere(['type' => 'event.triggered']);
        $this->assertSame('webhook', $line->origin);
        $this->assertSame('{"event":"deploy.start","payload":{"ref":"main"},"webhook":"CI"}', $line->details);

        $call = WebhookCall::findWhere(['webhook' => $created['webhook']['id']]);
        $this->assertSame('accepted', $call->status);
        $this->assertSame('{"ref":"main"}', $call->payload);
    }

    public function testCallWithASignatureIsAccepted()
    {
        $this->register('alice@example.com');
        $created = $this->createWebhook(['push']);
        $body = '{"push":{}}';

        $this->call($created['webhook']['uuid'], $body, ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $created['secret'])])->assertNoContent();
        $this->call($created['webhook']['uuid'], $body, ['X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $body, $created['secret'])])->assertNoContent();
        $this->call($created['webhook']['uuid'], '', ['X-Tower-Secret' => $created['secret']])->assertNoContent();

        $this->assertSame(['{"push":{}}', '{"push":{}}', '{}'], array_column($this->broadcast->emitted, 'payload'));
    }

    public function testRefusedCallsAreTracedButTriggerNothing()
    {
        $this->register('alice@example.com');
        $created = $this->createWebhook();
        $uuid = $created['webhook']['uuid'];
        $id = $created['webhook']['id'];

        $this->call($uuid, '{}')->assertUnauthorized();
        $this->call($uuid, '{}', ['X-Tower-Secret' => 'wrong'])->assertUnauthorized();
        $this->call($uuid, '{}', ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', '{"other":1}', $created['secret'])])->assertUnauthorized();
        $this->call($uuid, '[1]', ['X-Tower-Secret' => $created['secret']])->assertUnprocessableContent();
        $this->call($uuid, '{"a":"'.str_repeat('x', 40000).'"}', ['X-Tower-Secret' => $created['secret']])->assertUnprocessableContent();
        $this->call('00000000-0000-4000-8000-000000000000', '{}', ['X-Tower-Secret' => $created['secret']])->assertNotFound();

        $this->putJson("/api/webhooks/$id", ['name' => 'CI', 'events' => ['deploy.start'], 'enabled' => false])->assertOk();
        $this->call($uuid, '{}', ['X-Tower-Secret' => $created['secret']])->assertForbidden();

        $this->assertSame([], $this->broadcast->emitted);

        $calls = $this->get("/api/webhooks/$id/calls")->assertOk()->json();
        $this->assertSame(['disabled', 'invalid', 'invalid', 'unauthorized', 'unauthorized', 'unauthorized'], array_column($calls, 'status'));
        // The body of an unauthenticated call is not kept
        $this->assertNull($calls[3]['payload']);
        $this->assertNotNull(History::findWhere(['type' => 'webhook.disabled']));
    }

    public function testRefusedCallsAreListedForTheWorkspace()
    {
        $this->get('/api/webhooks/refused-calls')->assertUnauthorized();

        $this->register('alice@example.com');
        $created = $this->createWebhook();
        $uuid = $created['webhook']['uuid'];

        $refusals = fn () => count(array_filter($this->broadcast->signals, fn (array $signal) => WorkspaceSignals::WEBHOOK_REFUSED === $signal['type']));

        $this->call($uuid, '{}', ['X-Tower-Secret' => $created['secret']])->assertNoContent();
        $this->assertSame(0, $refusals());

        $this->call($uuid, '{}')->assertUnauthorized();
        $this->call($uuid, '[1]', ['X-Tower-Secret' => $created['secret']])->assertUnprocessableContent();

        $refused = $this->get('/api/webhooks/refused-calls')->assertOk()->json();
        $this->assertSame(['invalid', 'unauthorized'], array_column($refused, 'status'));
        $this->assertSame('CI', $refused[0]['webhook_name']);
        $this->assertSame(2, $refusals());

        $this->logOut();
        $this->register('bob@example.com');
        $this->assertSame([], $this->get('/api/webhooks/refused-calls')->assertOk()->json());
    }

    public function testUnsecuredWebhookNeedsNoSecret()
    {
        $this->register('alice@example.com');
        $created = $this->postJson('/api/webhooks', ['name' => 'Test', 'events' => ['ping'], 'secured' => false])->assertCreated()->json();
        $id = $created['webhook']['id'];

        $this->assertNull($created['secret']);
        $this->assertFalse($created['webhook']['secured']);
        $this->assertTrue($this->createWebhook()['webhook']['secured']);

        $this->logOut();
        $this->call($created['webhook']['uuid'], '{"hello":"world"}')->assertNoContent();
        $this->call($created['webhook']['uuid'], '[1]')->assertUnprocessableContent();
        $this->assertSame(['ping'], array_column($this->broadcast->emitted, 'event'));

        $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => 'correct horse battery'])->assertOk();

        // No secret to regenerate, and the choice cannot change
        $this->postJson("/api/webhooks/$id/secret")->assertConflict();
        $updated = $this->putJson("/api/webhooks/$id", ['name' => 'Test', 'events' => ['ping'], 'enabled' => true, 'secured' => true])->assertOk()->json();
        $this->assertFalse($updated['secured']);

        // An unsecured call is authenticated by its address : its body is kept
        $calls = $this->get("/api/webhooks/$id/calls")->assertOk()->json();
        $this->assertSame(['invalid', 'accepted'], array_column($calls, 'status'));
        $this->assertSame('{"hello":"world"}', $calls[1]['payload']);
    }

    public function testUpdateAndSecretRegeneration()
    {
        $this->register('alice@example.com');
        $created = $this->createWebhook();
        $id = $created['webhook']['id'];

        $updated = $this->putJson("/api/webhooks/$id", ['name' => 'Deploy', 'events' => ['deploy.end'], 'enabled' => true])->assertOk()->json();
        $this->assertSame('Deploy', $updated['name']);
        $this->assertSame(['deploy.end'], $updated['events']);
        $this->assertNull(History::findWhere(['type' => 'webhook.enabled']));

        $secret = $this->postJson("/api/webhooks/$id/secret")->assertOk()->json()['secret'];
        $this->assertNotSame($created['secret'], $secret);

        $this->call($created['webhook']['uuid'], '{}', ['X-Tower-Secret' => $created['secret']])->assertUnauthorized();
        $this->call($created['webhook']['uuid'], '{}', ['X-Tower-Secret' => $secret])->assertNoContent();
        $this->assertSame(['deploy.end'], array_column($this->broadcast->emitted, 'event'));
    }

    public function testDeletionRemovesTheWebhookAndItsCalls()
    {
        $this->register('alice@example.com');
        $created = $this->createWebhook();
        $this->call($created['webhook']['uuid'], '{}', ['X-Tower-Secret' => $created['secret']])->assertNoContent();

        $this->deleteJson('/api/webhooks/'.$created['webhook']['id'])->assertNoContent();

        $this->assertSame([], $this->get('/api/webhooks')->assertOk()->json());
        $this->assertNull(WebhookCall::findWhere(['webhook' => $created['webhook']['id']]));
        $this->call($created['webhook']['uuid'], '{}', ['X-Tower-Secret' => $created['secret']])->assertNotFound();
        // What it triggered stays in the history
        $this->assertNotNull(History::findWhere(['type' => 'event.triggered']));
    }

    public function testWebhooksStayInTheirWorkspace()
    {
        $this->register('alice@example.com');
        $id = $this->createWebhook()['webhook']['id'];

        $this->logOut();
        $this->register('bob@example.com');

        $this->assertSame([], $this->get('/api/webhooks')->assertOk()->json());
        $this->putJson("/api/webhooks/$id", ['name' => 'X', 'events' => ['a.b'], 'enabled' => false])->assertNotFound();
        $this->postJson("/api/webhooks/$id/secret")->assertNotFound();
        $this->get("/api/webhooks/$id/calls")->assertNotFound();
        $this->deleteJson("/api/webhooks/$id")->assertNotFound();
    }
}
