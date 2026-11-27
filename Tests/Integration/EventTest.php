<?php

namespace Tests\Integration;

use App\Domain\Workspace\WorkspaceSignals;
use App\Models\History;
use App\Session\SelectedWorkspace;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;
use Cube\Web\Websocket\Broadcast\Broadcast;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;

class EventTest extends CubeTestCase
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

    private function createAction(): array
    {
        return $this->postJson('/api/quick-actions', ['name' => 'Backup', 'type' => 'backup.run', 'payload' => '{"full":true}'])->assertCreated()->json();
    }

    public function testRoutesRequireAuthentication()
    {
        $this->postJson('/api/events', ['type' => 'a.b', 'payload' => '{}'])->assertUnauthorized();
        $this->get('/api/quick-actions')->assertUnauthorized();
        $this->get('/api/events/suggestions')->assertUnauthorized();
    }

    public function testSuggestionsGatherTheTypesOfTheWorkspace()
    {
        $this->register('alice@example.com');
        $this->assertSame([], $this->get('/api/events/suggestions')->assertOk()->json());

        $this->createAction();
        $this->postJson('/api/webhooks', ['name' => 'CI', 'events' => ['deploy.start', 'backup.run']])->assertCreated();
        $this->postJson('/api/scheduled-events', ['name' => 'Standup', 'type' => 'standup.remind', 'payload' => '{}', 'cron' => '0 9 * * 1-5'])->assertCreated();
        $this->postJson('/api/events', ['type' => 'notify.phone', 'payload' => '{}'])->assertNoContent();
        $this->postJson('/api/events', ['type' => 'notify.phone', 'payload' => '{}'])->assertNoContent();

        $suggestions = array_column($this->get('/api/events/suggestions')->assertOk()->json(), 'types', 'source');
        ksort($suggestions);
        $this->assertSame([
            'history' => ['notify.phone'],
            'quick_action' => ['backup.run'],
            'schedule' => ['standup.remind'],
            'webhook' => ['backup.run', 'deploy.start'],
        ], $suggestions);

        $this->logOut();
        $this->register('bob@example.com');
        $this->assertSame([], $this->get('/api/events/suggestions')->assertOk()->json());
    }

    public function testTriggeredEventIsJournaledAndSent()
    {
        $this->register('alice@example.com');

        $this->postJson('/api/events', ['type' => 'deploy.start', 'payload' => '{"env":"prod"}'])->assertNoContent();

        $event = History::findWhere(['type' => 'event.triggered']);
        $this->assertSame('user', $event->origin);
        $this->assertSame('{"event":"deploy.start","payload":{"env":"prod"}}', $event->details);

        $this->assertSame('event', $this->broadcast->emitted[0]['type']);
        $this->assertSame($event->workspace, $this->broadcast->emitted[0]['workspace']);
        $this->assertSame('deploy.start', $this->broadcast->emitted[0]['event']);
    }

    public function testInvalidEventsAreRefused()
    {
        $this->register('alice@example.com');

        foreach ([
            ['type' => 'Not Valid', 'payload' => '{}'],
            ['type' => 'a.b', 'payload' => 'nope'],
            ['type' => 'a.b', 'payload' => '[1]'],
            ['type' => 'a.b', 'payload' => '{"a":"'.str_repeat('x', 20000).'"}'],
        ] as $body) {
            $this->postJson('/api/events', $body)->assertUnprocessableContent();
        }

        $this->assertSame([], $this->broadcast->emitted);
    }

    public function testQuickActionLifecycle()
    {
        $this->register('alice@example.com');
        $action = $this->createAction();

        $this->assertSame(['Backup'], array_column($this->get('/api/quick-actions')->assertOk()->json(), 'name'));

        $this->postJson('/api/quick-actions/'.$action['id'].'/trigger', [])->assertNoContent();
        $this->assertSame('backup.run', $this->broadcast->emitted[0]['event']);
        $this->assertSame('{"full":true}', $this->broadcast->emitted[0]['payload']);

        $this->deleteJson('/api/quick-actions/'.$action['id'])->assertNoContent();
        $this->assertSame([], $this->get('/api/quick-actions')->assertOk()->json());
    }

    public function testQuickActionDescription()
    {
        $this->register('alice@example.com');

        $this->assertNull($this->createAction()['description']);

        $described = $this->postJson('/api/quick-actions', ['name' => 'Deploy', 'description' => 'Ships main to production', 'type' => 'deploy.start', 'payload' => '{}'])->assertCreated()->json();
        $this->assertSame('Ships main to production', $described['description']);

        $blank = $this->postJson('/api/quick-actions', ['name' => 'Blank', 'description' => '  ', 'type' => 'a.b', 'payload' => '{}'])->assertCreated()->json();
        $this->assertNull($blank['description']);

        $this->postJson('/api/quick-actions', ['name' => 'Long', 'description' => str_repeat('a', 256), 'type' => 'a.b', 'payload' => '{}'])->assertUnprocessableContent();
    }

    public function testQuickActionsStayInTheirWorkspace()
    {
        $this->register('alice@example.com');
        $action = $this->createAction();

        $this->logOut();
        $this->register('bob@example.com');

        $this->assertSame([], $this->get('/api/quick-actions')->assertOk()->json());
        $this->postJson('/api/quick-actions/'.$action['id'].'/trigger', [])->assertNotFound();
        $this->deleteJson('/api/quick-actions/'.$action['id'])->assertNotFound();
        $this->assertSame([], $this->broadcast->emitted);
    }
}
