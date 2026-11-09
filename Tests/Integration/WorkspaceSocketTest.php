<?php

namespace Tests\Integration;

use App\Channels\WorkspaceDevicesChannel;
use App\Models\Workspace;
use App\Security\WorkspaceSocketToken;
use App\Session\SelectedWorkspace;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;
use Cube\Web\Websocket\Servers\WebsocketConfiguration;
use GuzzleHttp\Psr7\Request;

class WorkspaceSocketTest extends CubeTestCase
{
    private function tokens(): WorkspaceSocketToken
    {
        return new WorkspaceSocketToken(new WebsocketConfiguration(broadcastSecret: 'a-secret-for-the-tests'));
    }

    private function authorize(string $url): ?string
    {
        return (new WorkspaceDevicesChannel($this->tokens()))->authorize(['workspace' => '12'], new Request('GET', $url));
    }

    public function testTokenIsValidForItsWorkspaceOnly()
    {
        $token = $this->tokens()->issue(12);

        $this->assertTrue($this->tokens()->isValid(12, $token));
        $this->assertFalse($this->tokens()->isValid(13, $token));
    }

    public function testTokenExpires()
    {
        $issuedAt = 1_000_000;
        $token = $this->tokens()->issue(12, $issuedAt);

        $this->assertTrue($this->tokens()->isValid(12, $token, $issuedAt + 60));
        $this->assertFalse($this->tokens()->isValid(12, $token, $issuedAt + 61));
    }

    public function testForgedTokensAreRefused()
    {
        [$expiresAt, $signature] = explode('.', $this->tokens()->issue(12));

        $this->assertFalse($this->tokens()->isValid(12, ($expiresAt + 3600).'.'.$signature));
        $this->assertFalse($this->tokens()->isValid(12, ''));
        $this->assertFalse($this->tokens()->isValid(12, 'garbage'));
    }

    public function testChannelRefusesAMissingOrInvalidToken()
    {
        $this->assertNotNull($this->authorize('/workspaces/12/devices'));
        $this->assertNotNull($this->authorize('/workspaces/12/devices?token=nope'));
        $this->assertNotNull($this->authorize('/workspaces/12/devices?token[]=nope'));
        $this->assertNotNull($this->authorize('/workspaces/12/devices?token='.$this->tokens()->issue(13)));
    }

    public function testChannelAcceptsAValidToken()
    {
        $this->assertNull($this->authorize('/workspaces/12/devices?token='.$this->tokens()->issue(12)));
    }

    public function testSocketRouteRequiresAuthentication()
    {
        Authentication::getInstance()->logout();

        $this->get('/api/workspaces/current/socket')->assertUnauthorized();
    }

    public function testSocketRouteTargetsTheCurrentWorkspace()
    {
        Authentication::getInstance()->logout();
        SelectedWorkspace::unset();
        $user = $this->postJson('/api/auth/register', ['email' => 'alice@example.com', 'password' => 'correct horse battery'])->assertCreated()->json();
        $workspace = Workspace::findWhere(['author' => $user['id']]);

        $url = $this->get('/api/workspaces/current/socket')->assertOk()->json()['url'];

        $this->assertStringContainsString("/workspaces/{$workspace->id}/devices?token=", $url);
    }
}
