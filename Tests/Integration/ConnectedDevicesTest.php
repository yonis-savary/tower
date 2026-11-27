<?php

namespace Tests\Integration;

use App\Channels\ConnectedDevices;
use App\Channels\DevicesChannel;
use App\Channels\WorkspaceDevicesChannel;
use App\Domain\AccessKey\AccessKeyService;
use App\Domain\History\HistoryService;
use App\Security\WorkspaceSocketToken;
use Cube\Test\CubeTestCase;
use GuzzleHttp\Psr7\Request;

class ConnectedDevicesTest extends CubeTestCase
{
    private function device(string $uuid): array
    {
        return ['uuid' => $uuid, 'hostname' => null, 'type' => 'unknown', 'user_agent' => null, 'access_key' => 'key', 'connected_at' => '2026-11-09T19:00:00+00:00'];
    }

    public function testListIsPublishedPerWorkspace()
    {
        $tokens = new WorkspaceSocketToken();
        $channel = new class($tokens) extends WorkspaceDevicesChannel {
            public array $published = [];

            public function emit(array $data, array $routeParams = []): bool
            {
                $this->published[] = [$routeParams['workspace'], array_column($data['devices'], 'uuid')];

                return true;
            }
        };
        $devices = new ConnectedDevices($channel);

        $devices->add(1, 12, $this->device('a'));
        $devices->add(2, 12, $this->device('b'));
        $devices->add(3, 13, $this->device('c'));
        $devices->remove(1);
        $devices->remove(1); // A connection that is already gone publishes nothing

        $this->assertSame([$this->device('b')], $devices->listFor(12));
        $this->assertSame([$this->device('c')], $devices->listFor(13));
        $this->assertSame([], $devices->listFor(14));
        $this->assertSame([[12, ['a']], [12, ['a', 'b']], [13, ['c']], [12, ['b']]], $channel->published);

        $this->assertSame([2], $devices->connectionsOf(12, 'b'));
        $this->assertSame([], $devices->connectionsOf(13, 'b')); // Another workspace cannot reach this device
        $this->assertSame([], $devices->connectionsOf(12, 'a')); // Already gone
    }

    private function describe(array $headers): array
    {
        return (new DevicesChannel(new AccessKeyService(), new HistoryService()))->describe(new Request('GET', '/devices', $headers), 'My key', 'an-uuid');
    }

    public function testDeviceIsDescribedFromItsHeaders()
    {
        $device = $this->describe([
            DevicesChannel::HEADER_HOSTNAME => 'my-laptop',
            DevicesChannel::HEADER_DEVICE_TYPE => 'laptop',
            'User-Agent' => 'tower-connector (Linux 6.8; x86_64; Python 3.13.1)',
        ]);

        $this->assertSame('an-uuid', $device['uuid']);
        $this->assertSame('my-laptop', $device['hostname']);
        $this->assertSame('laptop', $device['type']);
        $this->assertSame('tower-connector (Linux 6.8; x86_64; Python 3.13.1)', $device['user_agent']);
        $this->assertSame('My key', $device['access_key']);
    }

    public function testClientsWithoutOrWithBadHeadersAreStillDescribed()
    {
        $missing = $this->describe([]);
        $this->assertNull($missing['hostname']);
        $this->assertSame('unknown', $missing['type']);
        $this->assertNull($missing['user_agent']);

        $bad = $this->describe([
            DevicesChannel::HEADER_HOSTNAME => "broken\xFFname".str_repeat('x', 500),
            DevicesChannel::HEADER_DEVICE_TYPE => 'toaster',
        ]);
        $this->assertSame('unknown', $bad['type']);
        $this->assertLessThanOrEqual(DevicesChannel::MAX_HEADER_LENGTH, mb_strlen($bad['hostname']));
        $this->assertNotFalse(json_encode($bad));
    }
}
