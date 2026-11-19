<?php

namespace Tests\Integration;

use App\Integrations\Jira\JiraClient;
use App\Models\ApiToken;
use App\Models\History;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceSignals;
use App\Session\SelectedWorkspace;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;
use Cube\Web\Http\HttpMockServer;
use Cube\Web\Http\MockServers;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Websocket\Broadcast\Broadcast;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;

class McpTest extends CubeTestCase
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
            public array $signals = [];

            public function emit(string $event, array $data): bool
            {
                if (in_array($data['type'] ?? null, WorkspaceSignals::SIGNALS, true))
                    $this->signals[] = $data;

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

    private function register(string $email = 'alice@example.com'): void
    {
        $this->postJson('/api/auth/register', ['email' => $email, 'password' => 'correct horse battery'])->assertCreated();
    }

    private function setMcpAccess(string $access): void
    {
        $workspace = Workspace::find(SelectedWorkspace::get());
        $workspace->mcp_access = $access;
        $workspace->save();
    }

    private function createToken(string $scope = 'write', ?int $expiresInDays = null): array
    {
        return $this->postJson('/api/tokens', ['name' => 'Claude Code', 'scope' => $scope, 'expires_in_days' => $expiresInDays])->assertCreated()->json();
    }

    private function mcp(?string $token, string $method, array $params = [])
    {
        $headers = ['content-type' => 'application/json', 'accept' => 'application/json, text/event-stream', 'mcp-protocol-version' => '2025-11-25'];
        if (null !== $token)
            $headers['authorization'] = 'Bearer '.$token;
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], JSON_THROW_ON_ERROR);

        return $this->assertResponseOf(new Request('POST', '/mcp', [], [], $headers, body: $body));
    }

    private function toolNames(string $token): array
    {
        $tools = $this->mcp($token, 'tools/list')->assertOk()->json()['result']['tools'];

        return array_column($tools, 'name');
    }

    private function callTool(string $token, string $name, array $arguments = []): array
    {
        return $this->mcp($token, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments])->assertOk()->json()['result'];
    }

    public function testTokenRoutesRequireAuthentication()
    {
        $this->get('/api/tokens')->assertUnauthorized();
        $this->postJson('/api/tokens', ['name' => 'Claude', 'scope' => 'read'])->assertUnauthorized();
    }

    public function testTheValueIsOnlyGivenAtCreation()
    {
        $this->register();
        $created = $this->createToken('read', 30);

        $this->assertStringStartsWith('tower_', $created['value']);
        $this->assertSame('read', $created['token']['scope']);
        $this->assertNotNull($created['token']['expires_at']);

        $listed = $this->get('/api/tokens')->assertOk()->json();
        $this->assertCount(1, $listed);
        $this->assertArrayNotHasKey('value', $listed[0]);
        $this->assertSame(hash('sha256', $created['value']), ApiToken::find($created['token']['id'])->token_hash);
        $this->assertNotNull(History::findWhere(['type' => 'token.created']));
    }

    public function testInvalidTokensAreRefused()
    {
        $this->register();
        foreach ([
            ['name' => '', 'scope' => 'read'],
            ['name' => 'Claude', 'scope' => 'admin'],
            ['name' => 'Claude', 'scope' => 'read', 'expires_in_days' => 0],
        ] as $body) {
            $this->postJson('/api/tokens', $body)->assertUnprocessableContent();
        }
    }

    public function testTheEndpointRequiresAValidToken()
    {
        $this->register();
        $created = $this->createToken();
        $expired = $this->createToken();
        $expiredToken = ApiToken::find($expired['token']['id']);
        $expiredToken->expires_at = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $expiredToken->save();
        $this->delete('/api/tokens/'.$created['token']['id'])->assertNoContent();
        $this->logOut();

        $this->mcp(null, 'tools/list')->assertUnauthorized();
        $this->mcp('tower_unknown', 'tools/list')->assertUnauthorized();
        $this->mcp($created['value'], 'tools/list')->assertUnauthorized();
        $this->mcp($expired['value'], 'tools/list')->assertUnauthorized();
    }

    public function testInitializeAnswersWithTheServerInfo()
    {
        $this->register();
        $token = $this->createToken()['value'];
        $this->logOut();

        $result = $this->mcp($token, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ])->assertOk()->json()['result'];

        $this->assertSame('Tower', $result['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $result['capabilities']);
    }

    public function testAReadTokenOnlySeesTheReadTools()
    {
        $this->register();
        $this->setMcpAccess('write');
        $read = $this->createToken('read')['value'];
        $write = $this->createToken('write')['value'];
        $this->logOut();

        $readTools = $this->toolNames($read);
        $this->assertContains('list_tasks', $readTools);
        $this->assertNotContains('create_task', $readTools);

        $writeTools = $this->toolNames($write);
        $this->assertContains('list_tasks', $writeTools);
        $this->assertContains('create_task', $writeTools);

        $refused = $this->mcp($read, 'tools/call', ['name' => 'create_task', 'arguments' => ['title' => 'Nope']])->json();
        $this->assertTrue(isset($refused['error']) || ($refused['result']['isError'] ?? false));
        $this->assertSame(0, Task::select()->count());
    }

    public function testTheSchemaFollowsThePathAndTheRequestRules()
    {
        $this->register();
        $this->setMcpAccess('write');
        $token = $this->createToken()['value'];
        $this->logOut();

        $tools = array_column($this->mcp($token, 'tools/list')->json()['result']['tools'], null, 'name');
        $move = $tools['move_task'];

        $this->assertSame('integer', $move['inputSchema']['properties']['task']['type']);
        $this->assertContains('task', $move['inputSchema']['required']);
        $this->assertContains('status', $move['inputSchema']['required']);
        $this->assertNotContains('ordered', $move['inputSchema']['required']);
        $this->assertStringStartsWith('[write]', $move['description']);
        $this->assertTrue($tools['list_tasks']['annotations']['readOnlyHint']);
    }

    public function testACallActsAsTheUserOnTheWorkspaceOfTheToken()
    {
        $this->register();
        $this->setMcpAccess('write');
        $workspace = SelectedWorkspace::get();
        $token = $this->createToken()['value'];
        $this->logOut();

        $result = $this->callTool($token, 'create_task', ['title' => 'Written by an agent']);
        $this->assertFalse($result['isError'] ?? false);

        $task = Task::findWhere(['title' => 'Written by an agent']);
        $this->assertSame($workspace, $task->workspace);

        $line = History::findWhere(['type' => 'task.created']);
        $this->assertSame('mcp', $line->origin);
        $this->assertSame(User::findWhere(['email' => 'alice@example.com'])->id, $line->user);
        $this->assertSame('Claude Code', json_decode($line->details, true)['token']);

        $listed = $this->callTool($token, 'list_tasks');
        $this->assertStringContainsString('Written by an agent', $listed['content'][0]['text']);

        $moved = $this->callTool($token, 'move_task', ['task' => $task->id, 'sprint' => null, 'status' => 'done']);
        $this->assertFalse($moved['isError'] ?? false);
        $this->assertSame('done', Task::find($task->id)->status);
    }

    public function testARefusedCallIsAToolError()
    {
        $this->register();
        $this->setMcpAccess('write');
        $token = $this->createToken()['value'];
        $this->logOut();

        $missing = $this->callTool($token, 'move_task', ['task' => 999999, 'sprint' => null, 'status' => 'done']);
        $this->assertTrue($missing['isError']);
        $this->assertStringContainsString('404', $missing['content'][0]['text']);
    }

    public function testATokenStopsWorkingOutsideItsWorkspace()
    {
        $this->register('bob@example.com');
        $this->logOut();
        $this->register();
        $workspace = SelectedWorkspace::get();
        $this->postJson("/api/workspaces/{$workspace}/members", ['email' => 'bob@example.com'])->assertOk();
        $this->logOut();

        $this->postJson('/api/auth/login', ['email' => 'bob@example.com', 'password' => 'correct horse battery'])->assertOk();
        $this->putJson('/api/workspaces/current', ['workspace' => $workspace])->assertOk();
        $token = $this->createToken()['value'];
        $this->logOut();
        $this->assertContains('list_tasks', $this->toolNames($token));

        $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => 'correct horse battery'])->assertOk();
        $bob = User::findWhere(['email' => 'bob@example.com'])->id;
        $this->delete("/api/workspaces/{$workspace}/members/{$bob}")->assertNoContent();
        $this->logOut();

        $this->mcp($token, 'tools/list')->assertUnauthorized();
    }

    public function testNoSessionIsOpenedForAToken()
    {
        $this->register();
        $token = $this->createToken()['value'];
        $this->logOut();

        $this->callTool($token, 'list_tasks');

        $this->assertFalse(Authentication::getInstance()->isLogged());
    }

    public function testAWorkspaceIsReadOnlyByDefault()
    {
        $this->register();
        $this->assertSame('read', $this->get('/api/workspaces/current')->assertOk()->json()['mcp_access']);
        $token = $this->createToken('write')['value'];
        $this->logOut();

        $tools = $this->toolNames($token);
        $this->assertContains('list_tasks', $tools);
        $this->assertNotContains('create_task', $tools);
    }

    public function testABlockedWorkspaceRefusesTokens()
    {
        $this->register();
        $workspace = SelectedWorkspace::get();
        $token = $this->createToken()['value'];
        $this->putJson("/api/workspaces/{$workspace}", ['name' => 'Perso', 'accent_color' => null, 'mcp_access' => 'blocked'])->assertOk();
        $this->postJson('/api/tokens', ['name' => 'Claude', 'scope' => 'read'])->assertConflict();
        $this->logOut();

        $this->mcp($token, 'tools/list')->assertForbidden();
    }

    public function testAnUnknownAccessLevelIsRefused()
    {
        $this->register();
        $workspace = SelectedWorkspace::get();

        $this->putJson("/api/workspaces/{$workspace}", ['name' => 'Perso', 'accent_color' => null, 'mcp_access' => 'admin'])->assertUnprocessableContent();
    }

    public function testAnImageAttachmentIsGivenAsAnImage()
    {
        MockServers::getInstance()->set(JiraClient::class, HttpMockServer::fromArray([
            '/rest/api/3/myself' => fn () => Response::json(['accountId' => 'me-123', 'displayName' => 'Alice']),
            '/rest/agile/1.0/board/{int:board}/configuration' => fn () => Response::json(['columnConfig' => ['columns' => []]]),
            '/rest/agile/1.0/board/{int:board}/issue' => fn () => Response::json(['startAt' => 0, 'total' => 0, 'issues' => []]),
            '/rest/api/3/attachment/content/{id}' => fn () => new Response(200, "\x89PNG", ['Content-Type' => 'image/png']),
            '/rest/api/3/attachment/{id}' => fn () => Response::json(['id' => '501', 'filename' => 'mockup.png', 'mimeType' => 'image/png', 'size' => 4]),
        ]));
        $this->register();
        $this->putJson('/api/integrations/jira', ['site' => 'https://team.atlassian.net', 'email' => 'me@example.com', 'token' => 'the-api-token'])->assertOk();
        $token = $this->createToken('read')['value'];
        $this->logOut();

        $content = $this->callTool($token, 'get_jira_attachment', ['attachment' => 501])['content'][0];

        $this->assertSame(['image', 'image/png', base64_encode("\x89PNG")], [$content['type'], $content['mimeType'], $content['data']]);
    }

    public function testEachCallIsSignaledToTheCircuit()
    {
        $this->register();
        $created = $this->createToken();
        $this->logOut();

        $this->callTool($created['value'], 'list_tasks');
        $this->callTool($created['value'], 'list_task_history', ['task' => 999999]);

        $calls = array_values(array_map(
            fn (array $signal) => array_intersect_key($signal, array_flip(['type', 'token', 'tool', 'ok'])),
            array_filter($this->broadcast->signals, fn (array $signal) => 'mcp.called' === $signal['type']),
        ));
        $this->assertSame([
            ['type' => 'mcp.called', 'token' => $created['token']['id'], 'tool' => 'list_tasks', 'ok' => true],
            ['type' => 'mcp.called', 'token' => $created['token']['id'], 'tool' => 'list_task_history', 'ok' => false],
        ], $calls);
    }

    public function testTheCircuitListsTheTokensOfEveryMember()
    {
        $this->register('bob@example.com');
        $this->logOut();
        $this->register();
        $workspace = SelectedWorkspace::get();
        $this->postJson("/api/workspaces/{$workspace}/members", ['email' => 'bob@example.com'])->assertOk();
        $this->createToken('read');
        $this->logOut();

        $this->postJson('/api/auth/login', ['email' => 'bob@example.com', 'password' => 'correct horse battery'])->assertOk();
        $this->putJson('/api/workspaces/current', ['workspace' => $workspace])->assertOk();
        $this->postJson('/api/tokens', ['name' => 'Bob agent', 'scope' => 'read'])->assertCreated();

        $this->assertSame(['Bob agent'], array_column($this->get('/api/tokens')->assertOk()->json(), 'name'));
        $this->assertSame(['Claude Code', 'Bob agent'], array_column($this->get('/api/tokens/workspace')->assertOk()->json(), 'name'));
    }
}
