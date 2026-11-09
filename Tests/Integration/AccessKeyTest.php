<?php

namespace Tests\Integration;

use App\Channels\DevicesChannel;
use App\Connector\ConnectorConfiguration;
use App\Connector\ConnectorPackager;
use App\Models\AccessKey;
use App\Services\AccessKeyService;
use App\Session\SelectedWorkspace;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;
use Cube\Web\Websocket\Broadcast\Broadcast;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;
use GuzzleHttp\Psr7\Request;

class AccessKeyTest extends CubeTestCase
{
    private Broadcast $originalBroadcast;
    private object $broadcast;

    protected function setUp(): void
    {
        parent::setUp();
        Authentication::getInstance()->logout();
        SelectedWorkspace::unset();

        // Never reach the real websocket server : a test key could share its id with a real one
        $this->originalBroadcast = Broadcast::getInstance();
        $this->broadcast = new class(new BroadcastConfiguration()) extends Broadcast {
            public array $emitted = [];

            public function emit(string $event, array $data): bool
            {
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

    private function register(string $email): void
    {
        $this->postJson('/api/auth/register', ['email' => $email, 'password' => 'correct horse battery'])->assertCreated();
    }

    private function createKey(string $name = 'PC bureau'): array
    {
        return $this->postJson('/api/access-keys', ['name' => $name])->assertCreated()->json();
    }

    private function authorize(array $headers): ?string
    {
        return (new DevicesChannel(new AccessKeyService()))->authorize([], new Request('GET', '/devices', $headers));
    }

    private function credentials(array $created): array
    {
        return [DevicesChannel::HEADER_CLIENT_ID => $created['config']['client_id'], DevicesChannel::HEADER_SECRET => $created['config']['secret']];
    }

    public function testRoutesRequireAuthentication()
    {
        $this->get('/api/access-keys')->assertUnauthorized();
        $this->postJson('/api/access-keys', ['name' => 'x'])->assertUnauthorized();
        $this->deleteJson('/api/access-keys/1')->assertUnauthorized();
        $this->get('/api/connector')->assertUnauthorized();
    }

    public function testCreateGivesTheSecretOnceAndOnlyStoresItsHash()
    {
        $this->register('alice@example.com');

        $created = $this->createKey();

        $this->assertEquals('ws://localhost:8088/devices', preg_replace('~^.*//[^:]+~', 'ws://localhost', $created['config']['tower_url']));
        $this->assertSame(64, strlen($created['config']['secret']));
        $stored = AccessKey::find($created['key']['id']);
        $this->assertSame(hash('sha256', $created['config']['secret']), $stored->secret_hash);

        $listed = $this->get('/api/access-keys')->assertOk()->json();
        $this->assertEquals(['id', 'name', 'client_id', 'last_used_at'], array_keys($listed[0]));
    }

    public function testNameIsValidated()
    {
        $this->register('alice@example.com');

        $this->postJson('/api/access-keys', ['name' => '   '])->assertUnprocessableContent();
        $this->postJson('/api/access-keys', ['name' => str_repeat('a', 101)])->assertUnprocessableContent();
    }

    public function testKeysAreScopedToTheWorkspace()
    {
        $this->register('bob@example.com');
        $bobKey = $this->createKey();
        $this->post('/api/auth/logout')->assertNoContent();
        $this->register('alice@example.com');

        $this->assertSame([], $this->get('/api/access-keys')->assertOk()->json());
        $this->deleteJson('/api/access-keys/'.$bobKey['key']['id'])->assertNotFound();
        $this->postJson('/api/access-keys/'.$bobKey['key']['id'].'/connector', ['secret' => $bobKey['config']['secret']])->assertNotFound();
        $this->assertNull(AccessKey::find($bobKey['key']['id'])->revoked_at);
    }

    public function testChannelChecksCredentials()
    {
        $this->register('alice@example.com');
        $created = $this->createKey();

        $this->assertNull($this->authorize($this->credentials($created)));
        $this->assertNotNull($this->authorize([]));
        $this->assertNotNull($this->authorize([DevicesChannel::HEADER_CLIENT_ID => $created['config']['client_id'], DevicesChannel::HEADER_SECRET => 'wrong']));
    }

    public function testDisconnectingADeviceIsLimitedToTheCurrentWorkspace()
    {
        $this->postJson('/api/devices/an-uuid/disconnect', [])->assertUnauthorized();

        $this->register('alice@example.com');
        $this->postJson('/api/devices/an-uuid/disconnect', [])->assertNoContent();

        $this->assertSame('disconnect', $this->broadcast->emitted[0]['type']);
        $this->assertSame('an-uuid', $this->broadcast->emitted[0]['uuid']);
        $this->assertIsInt($this->broadcast->emitted[0]['workspace']);
    }

    public function testRevokingRefusesTheKeyAndDisconnectsItsDevices()
    {
        $this->register('alice@example.com');
        $created = $this->createKey();

        $this->deleteJson('/api/access-keys/'.$created['key']['id'])->assertNoContent();

        $this->assertNotNull($this->authorize($this->credentials($created)));
        $this->assertSame([], $this->get('/api/access-keys')->assertOk()->json());
        $this->assertSame('disconnect', $this->broadcast->emitted[0]['type']);
        $this->assertSame($created['key']['id'], $this->broadcast->emitted[0]['access_key']);
        $this->deleteJson('/api/access-keys/'.$created['key']['id'])->assertNotFound();
    }

    public function testPreconfiguredConnectorNeedsTheSecret()
    {
        $this->register('alice@example.com');
        $created = $this->createKey();

        $this->postJson('/api/access-keys/'.$created['key']['id'].'/connector', ['secret' => 'wrong'])->assertNotFound();
    }

    public function testConfigIsEmbeddedInACopyOfTheArchive()
    {
        $source = tempnam(sys_get_temp_dir(), 'pyz');
        $zip = new \ZipArchive();
        $zip->open($source, \ZipArchive::OVERWRITE);
        $zip->addFromString('__main__.py', 'pass');
        $zip->close();

        $packager = new ConnectorPackager(new ConnectorConfiguration($source), new DevicesChannel(new AccessKeyService()));
        $bytes = $packager->withEmbeddedConfig(['client_id' => 'twr_x']);

        $result = tempnam(sys_get_temp_dir(), 'pyz');
        file_put_contents($result, $bytes);
        $zip->open($result);
        $this->assertSame('pass', $zip->getFromName('__main__.py'));
        $this->assertSame(['client_id' => 'twr_x'], json_decode($zip->getFromName('tower_config.json'), true));
        $zip->close();

        $zip->open($source);
        $this->assertFalse($zip->getFromName('tower_config.json'));
        $zip->close();

        unlink($source);
        unlink($result);
    }
}
