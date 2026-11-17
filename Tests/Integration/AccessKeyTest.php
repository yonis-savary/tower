<?php

namespace Tests\Integration;

use App\Channels\DevicesChannel;
use App\Connector\ConnectorConfiguration;
use App\Connector\HandlerRules;
use App\Models\ConfigDownload;
use App\Connector\ConnectorPackager;
use App\Models\AccessKey;
use App\Services\AccessKeyService;
use App\Services\HistoryService;
use App\Services\WorkspaceSignals;
use App\Session\SelectedWorkspace;
use Cube\Env\Configuration;
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
        return (new DevicesChannel(new AccessKeyService(), new HistoryService()))->authorize([], new Request('GET', '/devices', $headers));
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
        $this->get('/api/connector/handler')->assertUnauthorized();
    }

    public function testBasicHandlerIsDownloadedWhenBuilt()
    {
        $this->register('alice@example.com');
        $handler = tempnam(sys_get_temp_dir(), 'pyz');
        file_put_contents($handler, 'handler-bytes');

        try {
            Configuration::getInstance()->add(new ConnectorConfiguration(handlerArchivePath: $handler));
            $this->assertSame('handler-bytes', $this->get('/api/connector/handler')->assertOk()->body());

            Configuration::getInstance()->add(new ConnectorConfiguration(handlerArchivePath: '/nowhere/tower-handler.pyz'));
            $this->get('/api/connector/handler')->assertResponseCode(503);
        } finally {
            Configuration::getInstance()->add(new ConnectorConfiguration());
        }
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
        $this->assertEquals(['id', 'name', 'client_id', 'listen', 'last_used_at', 'handlers'], array_keys($listed[0]));
        $this->assertSame([], $listed[0]['handlers']);
        $this->assertArrayNotHasKey('handlers', $created['config']);
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

    private function rules(): array
    {
        return [
            ['pattern' => 'deploy.*', 'run' => ['git pull', 'make deploy']],
            [
                'pattern' => '0',
                'run' => ['backup.sh "{payload.target}"'],
                'response' => ['mode' => 'fields', 'fields' => [['key' => 'log', 'value' => '{stdout}']]],
                'next' => [['type' => 'backup.done', 'payload' => [['key' => 'code', 'value' => '{exit_code}']]], ['type' => '{event}.after']],
            ],
            ['pattern' => 'task.*', 'run' => ['notify-send Tower'], 'response' => ['mode' => 'text', 'text' => 'notified {payload.title}']],
        ];
    }

    public function testHandlersAreWrittenInTheConnectorFormat()
    {
        $this->register('alice@example.com');

        $created = $this->postJson('/api/access-keys', ['name' => 'PC', 'handlers' => $this->rules()])->assertCreated()->json();

        $this->assertSame(['deploy.*', '0', 'task.*'], array_column($created['key']['handlers'], 'pattern'));
        $this->assertNull($created['key']['handlers'][0]['response']);
        $this->assertSame([], $created['key']['handlers'][0]['next']);
        $this->assertSame([], $created['key']['handlers'][1]['next'][1]['payload']);

        // As the connector reads it : one command alone, an object per response and payload, the order kept
        $this->assertSame(
            '{"deploy.*":{"run":["git pull","make deploy"]},"0":{"run":"backup.sh \"{payload.target}\"","response":{"log":"{stdout}"},"next":[{"type":"backup.done","payload":{"code":"{exit_code}"}},{"type":"{event}.after","payload":{}}]},"task.*":{"run":"notify-send Tower","response":"notified {payload.title}"}}',
            json_encode(HandlerRules::toConnector(HandlerRules::decode(AccessKey::find($created['key']['id'])->handlers)), JSON_UNESCAPED_SLASHES)
        );
        $this->assertSame(['deploy.*', '0', 'task.*'], array_map('strval', array_keys($created['config']['handlers'])));
    }

    public function testHandlersAreValidated()
    {
        $this->register('alice@example.com');
        $id = $this->createKey()['key']['id'];

        $invalid = [
            'no command' => [['pattern' => 'a', 'run' => []]],
            'blank command' => [['pattern' => 'a', 'run' => ['  ']]],
            'open quote' => [['pattern' => 'a', 'run' => ['echo "hello']]],
            'trailing backslash' => [['pattern' => 'a', 'run' => ['echo \\']]],
            'bad pattern' => [['pattern' => 'Deploy', 'run' => ['x']]],
            'same pattern twice' => [['pattern' => 'a', 'run' => ['x']], ['pattern' => 'a', 'run' => ['y']]],
            'empty text response' => [['pattern' => 'a', 'run' => ['x'], 'response' => ['mode' => 'text', 'text' => ' ']]],
            'no field' => [['pattern' => 'a', 'run' => ['x'], 'response' => ['mode' => 'fields', 'fields' => []]]],
            'same field twice' => [['pattern' => 'a', 'run' => ['x'], 'response' => ['mode' => 'fields', 'fields' => [['key' => 'k', 'value' => '1'], ['key' => 'k', 'value' => '2']]]]],
            'bad next type' => [['pattern' => 'a', 'run' => ['x'], 'next' => [['type' => 'Not A Type']]]],
            'too many next' => [['pattern' => 'a', 'run' => ['x'], 'next' => array_fill(0, 11, ['type' => 'b'])]],
        ];

        foreach ($invalid as $handlers)
            $this->putJson("/api/access-keys/$id/handlers", ['handlers' => $handlers])->assertUnprocessableContent();

        $this->assertSame('[]', AccessKey::find($id)->handlers);
    }

    public function testUpdatingHandlersRotatesTheSecret()
    {
        $this->register('alice@example.com');
        $created = $this->createKey();
        $id = $created['key']['id'];

        $updated = $this->putJson("/api/access-keys/$id/handlers", ['handlers' => $this->rules()])->assertOk()->json();

        $this->assertNotSame($created['config']['secret'], $updated['config']['secret']);
        $this->assertNotNull($this->authorize($this->credentials($created)));
        $this->assertNull($this->authorize($this->credentials($updated)));
        $this->assertSame('disconnect', $this->broadcast->emitted[0]['type']);
        $this->assertSame($id, $this->broadcast->emitted[0]['access_key']);
        $this->assertSame('secret_rotated', $this->broadcast->emitted[0]['reason']);
        $this->assertCount(3, $updated['config']['handlers']);

        // Back to no rule : the connector refuses an empty section, so there is none
        $cleared = $this->putJson("/api/access-keys/$id/handlers", ['handlers' => []])->assertOk()->json();
        $this->assertArrayNotHasKey('handlers', $cleared['config']);
    }

    public function testRotatingTheSecretKeepsTheHandlers()
    {
        $this->register('alice@example.com');
        $created = $this->postJson('/api/access-keys', ['name' => 'PC', 'handlers' => $this->rules()])->assertCreated()->json();
        $id = $created['key']['id'];

        $rotated = $this->postJson("/api/access-keys/$id/secret", [])->assertOk()->json();

        $this->assertNotNull($this->authorize($this->credentials($created)));
        $this->assertNull($this->authorize($this->credentials($rotated)));
        $this->assertSame($created['config']['handlers'], $rotated['config']['handlers']);
        $this->assertSame('secret_rotated', $this->broadcast->emitted[0]['reason']);
    }

    public function testDownloadLinkServesTheConfigurationOnce()
    {
        $this->register('alice@example.com');
        $created = $this->postJson('/api/access-keys', ['name' => 'PC', 'handlers' => $this->rules()])->assertCreated()->json();
        $id = $created['key']['id'];

        $this->postJson("/api/access-keys/$id/download-links", ['secret' => 'wrong', 'format' => 'json'])->assertNotFound();
        $this->postJson("/api/access-keys/$id/download-links", ['secret' => $created['config']['secret'], 'format' => 'zip'])->assertUnprocessableContent();

        $link = $this->postJson("/api/access-keys/$id/download-links", ['secret' => $created['config']['secret'], 'format' => 'json'])->assertCreated()->json();
        $this->assertStringStartsWith('/api/config-links/', $link['path']);

        // No session needed
        $this->post('/api/auth/logout')->assertNoContent();
        $downloaded = json_decode($this->get($link['path'])->assertOk()->body(), true);
        $this->assertSame($created['config']['secret'], $downloaded['secret']);
        $this->assertSame(['deploy.*', '0', 'task.*'], array_map('strval', array_keys($downloaded['handlers'])));

        $this->get($link['path'])->assertNotFound();
        $this->get('/api/config-links/unknown')->assertNotFound();
        $this->assertSame('', ConfigDownload::select()->fetch()[0]->payload);
    }

    public function testDownloadLinksExpireAndDieWithTheSecret()
    {
        $this->register('alice@example.com');
        $created = $this->createKey();
        $id = $created['key']['id'];
        $secret = $created['config']['secret'];

        $expired = $this->postJson("/api/access-keys/$id/download-links", ['secret' => $secret, 'format' => 'json'])->assertCreated()->json();
        ConfigDownload::update()->set('expires_at', date('Y-m-d H:i:s', time() - 1))->fetch();
        $this->get($expired['path'])->assertNotFound();

        $rotated = $this->postJson("/api/access-keys/$id/download-links", ['secret' => $secret, 'format' => 'json'])->assertCreated()->json();
        $this->postJson("/api/access-keys/$id/secret", [])->assertOk();
        $this->get($rotated['path'])->assertNotFound();
        $this->assertSame([], ConfigDownload::select()->fetch());
    }

    public function testConfigIsEmbeddedInACopyOfTheArchive()
    {
        $source = tempnam(sys_get_temp_dir(), 'pyz');
        $zip = new \ZipArchive();
        $zip->open($source, \ZipArchive::OVERWRITE);
        $zip->addFromString('__main__.py', 'pass');
        $zip->close();

        $packager = new ConnectorPackager(new ConnectorConfiguration($source), new DevicesChannel(new AccessKeyService(), new HistoryService()));
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
