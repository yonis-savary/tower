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
}
