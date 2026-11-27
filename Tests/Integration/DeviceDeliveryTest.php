<?php

namespace Tests\Integration;

use App\Channels\DevicesChannel;
use App\Domain\AccessKey\AccessKeyService;
use App\Domain\AccessKey\Connector\ConnectorConfiguration;
use App\Domain\History\HistoryService;
use App\Models\EventDelivery;
use App\Models\History;
use App\Models\Workspace;
use App\Session\SelectedWorkspace;
use Cube\Env\Configuration;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;
use Cube\Web\Websocket\Broadcast\Broadcast;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;
use GuzzleHttp\Psr7\Request;
use Ratchet\ConnectionInterface;

class DeviceDeliveryTest extends CubeTestCase
{
    private Broadcast $originalBroadcast;
    private DevicesChannel $channel;
    private AccessKeyService $keys;
    private HistoryService $history;
    private array $connections = [];

    protected function setUp(): void
    {
        parent::setUp();
        Authentication::getInstance()->logout();
        SelectedWorkspace::unset();

        // The device list published to the browsers must not reach the real websocket server
        $this->originalBroadcast = Broadcast::getInstance();
        Broadcast::setInstance(new class(new BroadcastConfiguration()) extends Broadcast {
            public function emit(string $event, array $data): bool
            {
                return true;
            }
        });

        $this->keys = new AccessKeyService();
        $this->history = new HistoryService();
        $this->channel = new DevicesChannel($this->keys, $this->history);
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection)
            $this->channel->unsubscribe($connection);
        Broadcast::setInstance($this->originalBroadcast);
        parent::tearDown();
    }

    private function workspace(string $email = 'alice@example.com'): int
    {
        $this->postJson('/api/auth/register', ['email' => $email, 'password' => 'correct horse battery'])->assertCreated();

        return $this->get('/api/workspaces/current')->assertOk()->json()['id'];
    }

    private function connect(int $workspace, array $listen): object
    {
        [$key, $secret] = $this->keys->create($workspace, 'Key '.count($this->connections), $listen);

        $connection = new class implements ConnectionInterface {
            public int $resourceId;
            public Request $httpRequest;
            public array $sent = [];

            public function send($data)
            {
                $this->sent[] = json_decode($data, true);

                return $this;
            }

            public function close() {}
        };
        $connection->resourceId = 9000 + count($this->connections);
        $connection->httpRequest = new Request('GET', '/devices', [
            DevicesChannel::HEADER_CLIENT_ID => $key->client_id,
            DevicesChannel::HEADER_SECRET => $secret,
        ]);

        $this->channel->subscribe('/devices', $connection);
        $this->connections[] = $connection;

        return $connection;
    }

    private function events(object $connection): array
    {
        return array_values(array_filter($connection->sent, fn (array $message) => 'event' === $message['type']));
    }

    private function send(int $workspace, string $event): int
    {
        $line = $this->history->log($workspace, $event, ['a' => 1]);
        $this->channel->dispatch('/devices', ['type' => 'event', 'workspace' => $workspace, 'id' => $line->id, 'event' => $event, 'payload' => '{"a":1}']);

        return $line->id;
    }

    private function answer(object $connection, array $data): void
    {
        $this->channel->onMessage($this->channel->getSubscriber($connection), $data);
    }

    public function testOnlyListeningDevicesReceiveTheEvent()
    {
        $workspace = $this->workspace();
        $tasks = $this->connect($workspace, ['task.*']);
        $deploys = $this->connect($workspace, ['deploy.start', 'deploy.?nd']);
        $deaf = $this->connect($workspace, []);

        $this->send($workspace, 'task.status_changed');
        $this->send($workspace, 'deploy.end');

        $this->assertSame(['task.status_changed'], array_column($this->events($tasks), 'event'));
        $this->assertSame(['deploy.end'], array_column($this->events($deploys), 'event'));
        $this->assertSame([], $this->events($deaf));
        $this->assertSame(2, EventDelivery::select()->count());
    }

    public function testDeliveryIsWrittenBeforeAndAnsweredLater()
    {
        $workspace = $this->workspace();
        $device = $this->connect($workspace, ['*']);
        $this->send($workspace, 'backup.run');

        $sent = $this->events($device)[0];
        $delivery = EventDelivery::find($sent['delivery']);
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(['a' => 1], $sent['payload']);
        $this->assertSame($workspace, $sent['workspace']);

        $this->answer($device, ['type' => 'response', 'delivery' => $delivery->id, 'status' => 'running']);
        $this->assertSame('running', EventDelivery::find($delivery->id)->status);

        $this->answer($device, ['type' => 'response', 'delivery' => $delivery->id, 'status' => 'failed', 'exit_code' => 2, 'output' => 'disk full']);
        $answered = EventDelivery::find($delivery->id);
        $this->assertSame('failed', $answered->status);
        $this->assertSame(2, $answered->exit_code);
        $this->assertSame('disk full', $answered->response);
        $this->assertNotNull($answered->responded_at);

        // A finished delivery keeps its result
        $this->answer($device, ['type' => 'response', 'delivery' => $delivery->id, 'status' => 'succeeded', 'output' => 'later']);
        $this->assertSame('failed', EventDelivery::find($delivery->id)->status);
    }

    /**
     * @return list<History>
     */
    private function chainedLines(): array
    {
        return History::select()->where('type', 'event.triggered')->where('origin', 'device')->fetch();
    }

    public function testNextEventsFollowASuccessfulAnswer()
    {
        $workspace = $this->workspace();
        $device = $this->connect($workspace, ['*']);
        $answered = $this->send($workspace, 'backup.run');
        $delivery = $this->events($device)[0]['delivery'];

        $this->answer($device, ['type' => 'response', 'delivery' => $delivery, 'status' => 'succeeded', 'output' => 'ok', 'next' => [
            ['type' => 'backup.done', 'payload' => ['size' => 3, 'files' => ['a', 'b']]],
            ['type' => 'notify.phone'],
            // The answered event itself : a loop of one step, refused
            ['type' => 'backup.run'],
        ]]);

        $lines = $this->chainedLines();
        $this->assertSame(['backup.done', 'notify.phone'], array_map(fn (History $line) => json_decode($line->details, true)['event'], $lines));
        $details = json_decode($lines[0]->details, true);
        $this->assertSame(['size' => 3, 'files' => ['a', 'b']], $details['payload']);
        $this->assertSame($answered, $details['answering']);
        $this->assertSame([], json_decode($lines[1]->details, true)['payload']);
    }

    public function testNextEventsNeedAFinalAnswerAndTheConfiguration()
    {
        $workspace = $this->workspace();
        $device = $this->connect($workspace, ['*']);

        $this->send($workspace, 'backup.run');
        $running = $this->events($device)[0]['delivery'];
        $this->answer($device, ['type' => 'response', 'delivery' => $running, 'status' => 'running', 'next' => [['type' => 'backup.done']]]);
        $this->assertSame([], $this->chainedLines());

        // Another handler of the same event failed : the connector still sends the next events of those that succeeded
        $this->answer($device, ['type' => 'response', 'delivery' => $running, 'status' => 'failed', 'next' => [['type' => 'backup.done']]]);
        $this->assertCount(1, $this->chainedLines());

        Configuration::getInstance()->add(new ConnectorConfiguration(allowNextEvents: false));
        try {
            $this->send($workspace, 'backup.run');
            $refused = $this->events($device)[1]['delivery'];
            $this->answer($device, ['type' => 'response', 'delivery' => $refused, 'status' => 'succeeded', 'next' => [['type' => 'backup.done']]]);
            $this->assertCount(1, $this->chainedLines());
        } finally {
            Configuration::getInstance()->add(new ConnectorConfiguration());
        }
    }

    public function testNextEventsAreValidated()
    {
        $rule = $this->channel->getMessageRule();
        $answer = fn (array $next) => ['type' => 'response', 'delivery' => 1, 'status' => 'succeeded', 'next' => $next];

        $this->assertTrue($rule->validate($answer([['type' => 'a.b', 'payload' => ['x' => 1]], ['type' => 'c']]))->isValid());
        $this->assertTrue($rule->validate($answer([['type' => 'a.b', 'payload' => []]]))->isValid());
        $this->assertFalse($rule->validate($answer([['type' => 'Not A Type']]))->isValid());
        $this->assertFalse($rule->validate($answer([['type' => 'a.b', 'payload' => [1, 2]]]))->isValid());
        $this->assertFalse($rule->validate($answer([['type' => 'a.b', 'payload' => 'text']]))->isValid());
        $this->assertFalse($rule->validate($answer(array_fill(0, 11, ['type' => 'a.b'])))->isValid());
    }

    public function testAnotherKeyCannotAnswerForADelivery()
    {
        $workspace = $this->workspace();
        $device = $this->connect($workspace, ['*']);
        $intruder = $this->connect($this->workspace('mallory@example.com'), ['*']);
        $this->send($workspace, 'backup.run');
        $delivery = $this->events($device)[0]['delivery'];

        $this->answer($intruder, ['type' => 'response', 'delivery' => $delivery, 'status' => 'succeeded', 'output' => 'forged']);

        $this->assertSame('pending', EventDelivery::find($delivery)->status);
        $this->assertNull(EventDelivery::find($delivery)->response);
    }

    public function testListenIsEditedFromTheFront()
    {
        $this->workspace();
        $key = $this->postJson('/api/access-keys', ['name' => 'Laptop'])->assertCreated()->json()['key'];
        $this->assertSame(['*'], $key['listen']);

        $updated = $this->putJson("/api/access-keys/{$key['id']}/listen", ['listen' => ['task.*', 'deploy.start']])->assertOk()->json();
        $this->assertSame(['task.*', 'deploy.start'], $updated['listen']);
        $this->assertSame(['task.*', 'deploy.start'], $this->get('/api/access-keys')->assertOk()->json()[0]['listen']);

        $this->putJson("/api/access-keys/{$key['id']}/listen", ['listen' => []])->assertOk();
        $this->putJson("/api/access-keys/{$key['id']}/listen", ['listen' => ['Task Created']])->assertUnprocessableContent();
        $this->putJson("/api/access-keys/{$key['id']}/listen", ['listen' => array_fill(0, 21, 'a.*')])->assertUnprocessableContent();
        $this->postJson('/api/access-keys', ['name' => 'Phone', 'listen' => ['mr.*']])->assertCreated();
    }
}
