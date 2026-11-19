<?php

namespace Tests\Integration;

use App\Integrations\IntegrationService;
use App\Models\AccessKey;
use App\Models\ApiToken;
use App\Models\ConfigDownload;
use App\Models\EventDelivery;
use App\Models\History;
use App\Models\Integration;
use App\Models\JiraIssue;
use App\Models\NotificationRule;
use App\Models\PullRequest;
use App\Models\PullRequestApprovals;
use App\Models\PushSubscription;
use App\Models\QuickAction;
use App\Models\ScheduledEvent;
use App\Models\Sprint;
use App\Models\SprintLink;
use App\Models\Task;
use App\Models\Webhook;
use App\Models\WebhookCall;
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
        $this->postJson('/api/workspaces', ['name' => 'x'])->assertUnauthorized();
        $this->get('/api/workspaces/1/members')->assertUnauthorized();
        $this->delete('/api/workspaces/1')->assertUnauthorized();
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

    public function testResponseOnlyExposesIdNameAndColor()
    {
        $this->register('alice@example.com');

        $this->assertEquals(['id', 'name', 'accent_color', 'sections', 'mcp_access', 'author'], array_keys($this->get('/api/workspaces')->assertOk()->json()[0]));
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

    public function testCreateAndRename()
    {
        $alice = $this->register('alice@example.com');

        $created = $this->postJson('/api/workspaces', ['name' => '  Perso  '])->assertCreated()->json();
        $this->assertSame('Perso', $created['name']);
        $this->assertEquals($this->defaultWorkspaceId($alice), $this->get('/api/workspaces/current')->assertOk()->json()['id']);
        $this->assertCount(2, $this->get('/api/workspaces')->assertOk()->json());

        $updated = $this->putJson("/api/workspaces/{$created['id']}", ['name' => 'Personnel', 'accent_color' => '#3B82F6'])->assertOk()->json();
        $this->assertSame(['Personnel', '#3b82f6'], [$updated['name'], $updated['accent_color']]);
        $this->assertSame('#3b82f6', Workspace::find($created['id'])->accent_color);

        $this->putJson("/api/workspaces/{$created['id']}", ['name' => 'Personnel', 'accent_color' => null])->assertOk();
        $this->assertNull(Workspace::find($created['id'])->accent_color);

        $this->assertSame(['tasks', 'jira', 'merge_requests', 'events', 'automation', 'webhooks'], $updated['sections']);
        $sections = $this->putJson("/api/workspaces/{$created['id']}", ['name' => 'Personnel', 'accent_color' => null, 'sections' => ['events', 'tasks']])->assertOk()->json()['sections'];
        $this->assertSame(['tasks', 'events'], $sections);
        $this->assertSame(['tasks', 'events'], $this->putJson("/api/workspaces/{$created['id']}", ['name' => 'Perso', 'accent_color' => null])->assertOk()->json()['sections']);
        $this->assertSame([], $this->putJson("/api/workspaces/{$created['id']}", ['name' => 'Perso', 'accent_color' => null, 'sections' => []])->assertOk()->json()['sections']);
        $this->putJson("/api/workspaces/{$created['id']}", ['name' => 'Perso', 'accent_color' => null, 'sections' => ['agenda']])->assertUnprocessableContent();

        $this->postJson('/api/workspaces', ['name' => '   '])->assertUnprocessableContent();
        $this->putJson("/api/workspaces/{$created['id']}", ['name' => str_repeat('x', 101), 'accent_color' => null])->assertUnprocessableContent();
        $this->putJson("/api/workspaces/{$created['id']}", ['name' => 'Personnel', 'accent_color' => 'red'])->assertUnprocessableContent();
    }

    public function testMembersAreAddedByEmailAndRemoved()
    {
        $bob = $this->register('bob@example.com');
        $this->logOut();
        $alice = $this->register('alice@example.com');
        $workspace = $this->defaultWorkspaceId($alice);

        $this->postJson("/api/workspaces/{$workspace}/members", ['email' => 'nobody@example.com'])->assertUnprocessableContent();
        $this->postJson("/api/workspaces/{$workspace}/members", ['email' => 'bob@example.com'])->assertOk();
        $this->postJson("/api/workspaces/{$workspace}/members", ['email' => 'bob@example.com'])->assertOk();
        $this->assertSame(['alice@example.com', 'bob@example.com'], array_column($this->get("/api/workspaces/{$workspace}/members")->assertOk()->json(), 'email'));

        $this->delete("/api/workspaces/{$workspace}/members/{$alice}")->assertNoContent();
        $this->get("/api/workspaces/{$workspace}/members")->assertNotFound();

        $this->logOut();
        $this->logIn('bob@example.com');
        $this->delete("/api/workspaces/{$workspace}/members/{$bob}")->assertConflict();
        $this->delete("/api/workspaces/{$workspace}/members/{$alice}")->assertNotFound();
    }

    public function testOnlyMembersManageAWorkspace()
    {
        $this->register('bob@example.com');
        $this->logOut();
        $alice = $this->register('alice@example.com');
        $workspace = $this->defaultWorkspaceId($alice);
        $this->logOut();
        $this->logIn('bob@example.com');

        $this->putJson("/api/workspaces/{$workspace}", ['name' => 'Taken', 'accent_color' => null])->assertNotFound();
        $this->get("/api/workspaces/{$workspace}/members")->assertNotFound();
        $this->postJson("/api/workspaces/{$workspace}/members", ['email' => 'bob@example.com'])->assertNotFound();
        $this->delete("/api/workspaces/{$workspace}/members/{$alice}")->assertNotFound();
    }

    /**
     * @return array<class-string,int>
     */
    private function fillWorkspace(int $workspace, int $user): array
    {
        $sprint = Sprint::factory()->create(['workspace' => $workspace]);
        $jira = Integration::factory()->create(['workspace' => $workspace, 'type' => IntegrationService::TYPE_JIRA]);
        $bitbucket = Integration::factory()->create(['workspace' => $workspace, 'type' => IntegrationService::TYPE_BITBUCKET]);
        $issue = JiraIssue::factory()->create(['workspace' => $workspace, 'integration' => $jira->id, 'parent' => null]);
        $subtask = JiraIssue::factory()->create(['workspace' => $workspace, 'integration' => $jira->id, 'parent' => $issue->id]);
        $pullRequest = PullRequest::factory()->create(['workspace' => $workspace, 'integration' => $bitbucket->id]);
        $task = Task::factory()->create(['workspace' => $workspace, 'sprint' => $sprint->id, 'jira_issue' => $subtask->id]);
        $history = History::factory()->create(['workspace' => $workspace, 'user' => $user, 'task' => $task->id]);
        $accessKey = AccessKey::factory()->create(['workspace' => $workspace]);
        $webhook = Webhook::factory()->create(['workspace' => $workspace]);

        return [
            Sprint::class => $sprint->id,
            SprintLink::class => SprintLink::factory()->create(['sprint' => $sprint->id])->id,
            Integration::class => $jira->id,
            JiraIssue::class => $subtask->id,
            PullRequest::class => $pullRequest->id,
            PullRequestApprovals::class => PullRequestApprovals::factory()->create(['pull_request' => $pullRequest->id])->id,
            Task::class => $task->id,
            History::class => $history->id,
            EventDelivery::class => EventDelivery::factory()->create(['history' => $history->id, 'access_key' => $accessKey->id])->id,
            AccessKey::class => $accessKey->id,
            ConfigDownload::class => ConfigDownload::factory()->create(['access_key' => $accessKey->id])->id,
            Webhook::class => $webhook->id,
            WebhookCall::class => WebhookCall::factory()->create(['webhook' => $webhook->id])->id,
            ApiToken::class => ApiToken::factory()->create(['workspace' => $workspace, 'user' => $user])->id,
            QuickAction::class => QuickAction::factory()->create(['workspace' => $workspace])->id,
            ScheduledEvent::class => ScheduledEvent::factory()->create(['workspace' => $workspace])->id,
            NotificationRule::class => NotificationRule::factory()->create(['workspace' => $workspace, 'user' => $user])->id,
            PushSubscription::class => PushSubscription::factory()->create(['workspace' => $workspace, 'user' => $user])->id,
        ];
    }

    public function testAuthorDeletesWorkspaceAndEverythingItHolds()
    {
        $bob = $this->register('bob@example.com');
        $this->logOut();
        $alice = $this->register('alice@example.com');
        $kept = $this->defaultWorkspaceId($alice);
        $doomed = $this->addWorkspace($alice, 'Doomed');
        WorkspaceMember::fromArray(['workspace' => $doomed, 'user' => $bob])->save();
        $this->putJson('/api/workspaces/current', ['workspace' => $doomed])->assertOk();

        $deleted = $this->fillWorkspace($doomed, $alice);
        $untouched = $this->fillWorkspace($kept, $alice);

        $this->delete("/api/workspaces/{$doomed}")->assertNoContent();

        $this->assertNull(Workspace::find($doomed));
        $this->assertFalse(WorkspaceMember::existsWhere(['workspace' => $doomed]));
        foreach ($deleted as $model => $id)
            $this->assertNull($model::find($id), "{$model} #{$id} survived the deletion");
        foreach ($untouched as $model => $id)
            $this->assertNotNull($model::find($id), "{$model} #{$id} of another workspace was deleted");

        $this->assertEquals($kept, $this->get('/api/workspaces/current')->assertOk()->json()['id']);
        $this->assertSame([$kept], array_column($this->get('/api/workspaces')->assertOk()->json(), 'id'));
    }

    public function testOnlyTheAuthorDeletesAWorkspace()
    {
        $bob = $this->register('bob@example.com');
        $this->logOut();
        $alice = $this->register('alice@example.com');
        $shared = $this->addWorkspace($alice, 'Shared');
        WorkspaceMember::fromArray(['workspace' => $shared, 'user' => $bob])->save();
        $this->logOut();
        $this->logIn('bob@example.com');

        $this->delete("/api/workspaces/{$shared}")->assertForbidden();
        $this->delete("/api/workspaces/{$this->defaultWorkspaceId($alice)}")->assertNotFound();
        $this->assertNotNull(Workspace::find($shared));
    }

    public function testLastWorkspaceOfTheAuthorIsKept()
    {
        $alice = $this->register('alice@example.com');
        $workspace = $this->defaultWorkspaceId($alice);

        $this->delete("/api/workspaces/{$workspace}")->assertConflict();
        $this->assertNotNull(Workspace::find($workspace));
    }
}
