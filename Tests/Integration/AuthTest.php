<?php

namespace Tests\Integration;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Cube\Data\Database\Database;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;

class AuthTest extends CubeTestCase
{
    private const EMAIL = 'alice@example.com';
    private const PASSWORD = 'correct horse battery';

    protected function setUp(): void
    {
        parent::setUp();
        Authentication::getInstance()->logout();
    }

    private function register(): array
    {
        return $this->postJson('/api/auth/register', ['email' => self::EMAIL, 'password' => self::PASSWORD])
            ->assertCreated()
            ->json();
    }

    public function testRegisterCreatesFirstWorkspaceAndLogsIn()
    {
        $user = $this->register();

        $this->assertEquals(['id', 'email'], array_keys($user));

        $createdAt = Database::getInstance()->query('SELECT created_at FROM user WHERE id = {}', [$user['id']])[0]['created_at'];
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $createdAt);

        $workspace = Workspace::findWhere(['author' => $user['id']]);
        $this->assertNotNull($workspace);
        $this->assertNotNull(WorkspaceMember::findWhere(['workspace' => $workspace->id, 'user' => $user['id']]));

        $this->get('/api/auth/me')->assertOk()->assertJsonContent($user);
    }

    public function testRegisterRefusesUsedEmailWhateverItsCase()
    {
        $this->register();

        $this->postJson('/api/auth/register', ['email' => strtoupper(self::EMAIL), 'password' => self::PASSWORD])
            ->assertResponseCode(409);
    }

    public function testRegisterValidatesInput()
    {
        $this->postJson('/api/auth/register', ['email' => 'nope', 'password' => self::PASSWORD])->assertUnprocessableContent();
        $this->postJson('/api/auth/register', ['email' => self::EMAIL, 'password' => 'short'])->assertUnprocessableContent();
        $this->postJson('/api/auth/register', ['email' => self::EMAIL, 'password' => str_repeat('a', 73)])->assertUnprocessableContent();
    }

    public function testLoginAndLogout()
    {
        $user = $this->register();
        $this->post('/api/auth/logout')->assertNoContent();
        $this->get('/api/auth/me')->assertUnauthorized();

        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonContent($user);
        $this->get('/api/auth/me')->assertOk();
    }

    private function logIn(): void
    {
        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])->assertOk();
    }

    private function deleteAuthoredWorkspaces(int $userId): void
    {
        foreach (Workspace::select()->where('author', $userId)->fetch() as $workspace) {
            WorkspaceMember::delete()->where('workspace', $workspace->id)->fetch();
            $workspace->destroy();
        }
    }

    private function authoredWorkspacesCount(int $userId): int
    {
        return count(Workspace::select()->where('author', $userId)->fetch());
    }

    public function testLoginCreatesDefaultWorkspaceWhenUserHasNone()
    {
        $user = $this->register();
        $this->post('/api/auth/logout')->assertNoContent();
        $this->deleteAuthoredWorkspaces($user['id']);

        $this->logIn();

        $workspace = Workspace::findWhere(['author' => $user['id']]);
        $this->assertNotNull($workspace);
        $this->assertNotNull(WorkspaceMember::findWhere(['workspace' => $workspace->id, 'user' => $user['id']]));
    }

    public function testLoginDoesNotDuplicateDefaultWorkspace()
    {
        $user = $this->register();
        $this->post('/api/auth/logout')->assertNoContent();

        $this->logIn();

        $this->assertEquals(1, $this->authoredWorkspacesCount($user['id']));
    }

    public function testBeingMemberOfAnotherWorkspaceDoesNotCount()
    {
        $user = $this->register();
        $this->post('/api/auth/logout')->assertNoContent();
        $this->deleteAuthoredWorkspaces($user['id']);

        $owner = $this->postJson('/api/auth/register', ['email' => 'bob@example.com', 'password' => self::PASSWORD])->assertCreated()->json();
        $this->post('/api/auth/logout')->assertNoContent();
        $ownerWorkspace = Workspace::findWhere(['author' => $owner['id']]);
        WorkspaceMember::fromArray(['workspace' => $ownerWorkspace->id, 'user' => $user['id']])->save();

        $this->logIn();

        $this->assertEquals(1, $this->authoredWorkspacesCount($user['id']));
    }

    public function testLoginRefusesWrongPassword()
    {
        $this->register();
        $this->post('/api/auth/logout')->assertNoContent();

        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => 'wrong password'])->assertUnauthorized();
        $this->get('/api/auth/me')->assertUnauthorized();
    }

    public function testDisabledUserCannotLogIn()
    {
        $this->register();
        $this->post('/api/auth/logout')->assertNoContent();

        $user = User::findWhere(['email' => self::EMAIL]);
        $user->disabled_at = new \DateTime();
        $user->save();

        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])->assertUnauthorized();
    }

    public function testChangePasswordChecksTheCurrentOne()
    {
        $this->register();
        $this->putJson('/api/auth/password', ['current_password' => 'wrong password', 'new_password' => 'a new long password'])->assertUnprocessableContent();
        $this->putJson('/api/auth/password', ['current_password' => self::PASSWORD, 'new_password' => 'short'])->assertUnprocessableContent();
        $this->putJson('/api/auth/password', ['current_password' => self::PASSWORD, 'new_password' => 'a new long password'])->assertNoContent();

        $this->post('/api/auth/logout')->assertNoContent();
        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => 'a new long password'])->assertOk();

        $this->post('/api/auth/logout')->assertNoContent();
        $this->putJson('/api/auth/password', ['current_password' => 'a new long password', 'new_password' => 'x'.self::PASSWORD])->assertUnauthorized();
    }
}
