<?php

namespace Tests\Integration;

use App\Models\EventDelivery;
use App\Models\History;
use App\Services\HistoryConfiguration;
use App\Services\HistoryService;
use App\Services\WorkspaceSignals;
use App\Session\SelectedWorkspace;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;
use Cube\Web\Websocket\Broadcast\Broadcast;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;

class HistoryTest extends CubeTestCase
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

    private function lines(string $query = ''): array
    {
        return $this->get('/api/history'.$query)->assertOk()->json();
    }

    public function testRouteRequiresAuthentication()
    {
        $this->get('/api/history')->assertUnauthorized();
    }

    public function testActionsAreLoggedNewestFirst()
    {
        $this->register('alice@example.com');

        $created = $this->postJson('/api/access-keys', ['name' => 'PC'])->assertCreated()->json();
        $this->postJson('/api/events', ['type' => 'deploy.start', 'payload' => '{}'])->assertNoContent();
        $this->deleteJson('/api/access-keys/'.$created['key']['id'])->assertNoContent();

        $lines = $this->lines();
        $this->assertSame(['key.revoked', 'event.triggered', 'key.created'], array_column($lines, 'type'));
        $this->assertSame(['name' => 'PC'], json_decode($lines[0]['details'], true));
        $this->assertSame('{"event":"deploy.start","payload":{}}', $lines[1]['details']);
        $this->assertSame('user', $lines[1]['origin']);
        $this->assertSame($lines[1]['id'], $this->broadcast->emitted[0]['id']);
    }

    public function testPagesGoBackInTime()
    {
        $this->register('alice@example.com');
        $this->postJson('/api/events', ['type' => 'a', 'payload' => '{}'])->assertNoContent();
        $this->postJson('/api/events', ['type' => 'b', 'payload' => '{}'])->assertNoContent();

        $newest = $this->lines()[0];
        $older = $this->lines('?before='.$newest['id']);

        $this->assertCount(1, $older);
        $this->assertSame('{"event":"a","payload":{}}', $older[0]['details']);
    }

    public function testDeliveriesAndLinesStayInTheirWorkspace()
    {
        $this->register('alice@example.com');
        $this->postJson('/api/events', ['type' => 'a', 'payload' => '{"x":1}'])->assertNoContent();
        $line = $this->lines()[0];
        (new HistoryService())->logDelivery($line['id'], 'an-uuid', 'my-host', '{"x":1}');

        $deliveries = $this->get('/api/history/'.$line['id'].'/deliveries')->assertOk()->json();
        $this->assertSame('my-host', $deliveries[0]['hostname']);
        $this->assertSame('{"x":1}', $deliveries[0]['payload']);
        $this->assertNull($deliveries[0]['response']);

        $this->logOut();
        $this->register('bob@example.com');
        $this->assertSame([], $this->lines());
        $this->get('/api/history/'.$line['id'].'/deliveries')->assertNotFound();
    }

    public function testRecentEventsKeepOnlyEventsWithTheirDeliveries()
    {
        $this->get('/api/history/events')->assertUnauthorized();

        $this->register('alice@example.com');
        $this->postJson('/api/access-keys', ['name' => 'PC'])->assertCreated();
        $this->postJson('/api/events', ['type' => 'deploy.start', 'payload' => '{}'])->assertNoContent();
        $this->postJson('/api/tasks', ['title' => 'Write the docs'])->assertCreated();
        $event = History::findWhere(['type' => 'event.triggered']);
        $history = new HistoryService();
        $integration = $history->log($event->workspace, 'pr.pipeline.failed', ['title' => 'Fix'], HistoryService::ORIGIN_INTEGRATION);
        $history->logDelivery($event->id, 'uuid-1', 'pc', '{}');
        $history->logDelivery($event->id, 'uuid-2', 'laptop', '{}');
        $failed = EventDelivery::findWhere(['connection_uuid' => 'uuid-2']);
        $failed->status = HistoryService::DELIVERY_FAILED;
        $failed->save();

        $events = $this->get('/api/history/events')->assertOk()->json();
        $this->assertSame([$integration->id, $event->id], array_column($events, 'id'));
        $this->assertSame(['succeeded' => 0, 'failed' => 0, 'waiting' => 0], $events[0]['deliveries']);
        $this->assertSame(['succeeded' => 0, 'failed' => 1, 'waiting' => 1], $events[1]['deliveries']);

        $this->logOut();
        $this->register('bob@example.com');
        $this->assertSame([], $this->get('/api/history/events')->assertOk()->json());
    }

    public function testBrowsersAreToldWhatChanged()
    {
        $this->register('alice@example.com');
        $this->postJson('/api/events', ['type' => 'deploy.start', 'payload' => '{}'])->assertNoContent();
        $line = History::findWhere(['type' => 'event.triggered']);

        $this->assertContains(['type' => WorkspaceSignals::HISTORY_CREATED, 'history_type' => 'event.triggered'], array_map(
            fn (array $signal) => array_diff_key($signal, ['__class' => true]),
            $this->broadcast->signals,
        ));

        $this->broadcast->signals = [];
        (new HistoryService())->logDelivery($line->id, 'uuid-1', 'pc', '{}');
        $this->assertSame(WorkspaceSignals::DELIVERY_UPDATED, $this->broadcast->signals[0]['type']);
        $this->assertSame($line->id, $this->broadcast->signals[0]['history']);
    }

    public function testPurgeRemovesOnlyExpiredLinesAndTheirDeliveries()
    {
        $this->register('alice@example.com');
        $this->postJson('/api/events', ['type' => 'old', 'payload' => '{}'])->assertNoContent();
        $this->postJson('/api/events', ['type' => 'new', 'payload' => '{}'])->assertNoContent();
        [$new, $old] = $this->lines();

        $history = new HistoryService();
        $history->logDelivery($old['id'], 'an-uuid', null, '{}');
        $expired = History::find($old['id']);
        $expired->created_at = date('Y-m-d H:i:s', strtotime('-40 days'));
        $expired->save();

        $history->purgeExpired(new HistoryConfiguration(retentionDays: 31));

        $this->assertSame([$new['id']], array_column($this->lines(), 'id'));
        $this->assertNull(EventDelivery::findWhere(['history' => $old['id']]));
    }
}
