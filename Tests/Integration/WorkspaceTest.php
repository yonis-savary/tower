<?php

namespace Tests\Integration;

use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Session\SelectedWorkspace;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;

class WorkspaceTest extends CubeTestCase
{
    private const PASSWORD = 'correct horse battery';

    protected function setUp(): void
    {
        parent::setUp();
        Authentication::getInstance()->logout();
        SelectedWorkspace::unset();
    }

    private function register(string $email): int
    {
        return $this->postJson('/api/auth/register', ['email' => $email, 'password' => self::PASSWORD])
            ->assertCreated()
            ->json()['id'];
    }

    private function logIn(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => self::PASSWORD])->assertOk();
    }

    private function logOut(): void
    {
        $this->post('/api/auth/logout')->assertNoContent();
    }

    private function addWorkspace(int $userId, string $name): int
    {
        $workspace = Workspace::fromArray(['name' => $name, 'author' => $userId])->save();
        WorkspaceMember::fromArray(['workspace' => $workspace->id, 'user' => $userId])->save();

        return $workspace->id;
    }

    private function defaultWorkspaceId(int $userId): int
    {
        return Workspace::findWhere(['author' => $userId])->id;
    }

    public function testRoutesRequireAuthentication()
    {
        $this->get('/api/workspaces')->assertUnauthorized();
        $this->get('/api/workspaces/current')->assertUnauthorized();
        $this->putJson('/api/workspaces/current', ['workspace' => 1])->assertUnauthorized();
    }

    public function testListOnlyContainsWorkspacesOfTheUser()
    {
        $bob = $this->register('bob@example.com');
        $this->logOut();
        $bobWorkspace = $this->defaultWorkspaceId($bob);

        $alice = $this->register('alice@example.com');
        $aliceWorkspace = $this->defaultWorkspaceId($alice);
        $shared = $this->addWorkspace($bob, 'Shared');
        WorkspaceMember::fromArray(['workspace' => $shared, 'user' => $alice])->save();

        $ids = array_column($this->get('/api/workspaces')->assertOk()->json(), 'id');

        $this->assertEquals([$aliceWorkspace, $shared], $ids);
        $this->assertNotContains($bobWorkspace, $ids);
    }

    public function testResponseOnlyExposesIdAndName()
    {
        $this->register('alice@example.com');

        $this->assertEquals(['id', 'name'], array_keys($this->get('/api/workspaces')->assertOk()->json()[0]));
    }

    public function testRegisterSelectsDefaultWorkspace()
    {
        $alice = $this->register('alice@example.com');

        $this->assertEquals($this->defaultWorkspaceId($alice), $this->get('/api/workspaces/current')->assertOk()->json()['id']);
    }

    public function testSelectChangesCurrentWorkspace()
    {
        $alice = $this->register('alice@example.com');
        $second = $this->addWorkspace($alice, 'Second');

        $this->putJson('/api/workspaces/current', ['workspace' => $second])->assertOk();

        $this->assertEquals('Second', $this->get('/api/workspaces/current')->assertOk()->json()['name']);
    }

    public function testCannotSelectWorkspaceOfSomeoneElse()
    {
        $bob = $this->register('bob@example.com');
        $this->logOut();
        $alice = $this->register('alice@example.com');

        $this->putJson('/api/workspaces/current', ['workspace' => $this->defaultWorkspaceId($bob)])->assertNotFound();

        $this->assertEquals($this->defaultWorkspaceId($alice), $this->get('/api/workspaces/current')->assertOk()->json()['id']);
    }

    public function testLoginGoesBackToFirstWorkspace()
    {
        $alice = $this->register('alice@example.com');
        $second = $this->addWorkspace($alice, 'Second');
        $this->putJson('/api/workspaces/current', ['workspace' => $second])->assertOk();
        $this->logOut();

        $this->logIn('alice@example.com');

        $this->assertEquals($this->defaultWorkspaceId($alice), $this->get('/api/workspaces/current')->assertOk()->json()['id']);
    }

    public function testLogoutForgetsSelection()
    {
        $this->register('alice@example.com');

        $this->logOut();

        $this->assertNull(SelectedWorkspace::get());
    }

    public function testCurrentFallsBackWhenUserLeftTheSelectedWorkspace()
    {
        $alice = $this->register('alice@example.com');
        $second = $this->addWorkspace($alice, 'Second');
        $this->putJson('/api/workspaces/current', ['workspace' => $second])->assertOk();

        WorkspaceMember::findWhere(['workspace' => $second, 'user' => $alice])->destroy();

        $this->assertEquals($this->defaultWorkspaceId($alice), $this->get('/api/workspaces/current')->assertOk()->json()['id']);
    }
}
