<?php

namespace Tests\Integration;

use App\Integrations\IntegrationService;
use App\Models\DocPage;
use App\Models\Integration;
use App\Models\JiraIssue;
use App\Models\PullRequest;
use App\Models\QuickAction;
use App\Models\Task;
use App\Models\Workspace;
use App\Session\SelectedWorkspace;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;

class SearchTest extends CubeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Authentication::getInstance()->logout();
        SelectedWorkspace::unset();
    }

    private function register(string $email = 'alice@example.com'): int
    {
        return $this->postJson('/api/auth/register', ['email' => $email, 'password' => 'correct horse battery'])->assertCreated()->json()['id'];
    }

    private function search(string $text): array
    {
        return $this->get('/api/search?q='.rawurlencode($text))->assertOk()->json();
    }

    private function fill(int $workspace, int $user): void
    {
        $bitbucket = Integration::factory()->create(['workspace' => $workspace, 'type' => IntegrationService::TYPE_BITBUCKET]);
        $jira = Integration::factory()->create(['workspace' => $workspace, 'type' => IntegrationService::TYPE_JIRA]);

        PullRequest::factory()->create(['workspace' => $workspace, 'integration' => $bitbucket->id, 'title' => 'Login form, merged', 'state' => 'MERGED', 'url' => 'https://bitbucket.org/acme/app/pull-requests/1']);
        PullRequest::factory()->create(['workspace' => $workspace, 'integration' => $bitbucket->id, 'title' => 'Login form', 'state' => 'OPEN', 'url' => 'https://bitbucket.org/acme/app/pull-requests/2']);
        JiraIssue::factory()->create(['workspace' => $workspace, 'integration' => $jira->id, 'parent' => null, 'issue_key' => 'APP-42', 'summary' => 'The login form refuses accents', 'tracked' => true, 'status_category' => 'in_progress']);
        Task::factory()->create(['workspace' => $workspace, 'sprint' => null, 'jira_issue' => null, 'title' => 'Write the LOGIN tests', 'status' => 'todo']);
        QuickAction::factory()->create(['workspace' => $workspace, 'name' => 'Deploy login', 'type' => 'deploy.login', 'description' => null]);
        DocPage::factory()->create(['workspace' => $workspace, 'parent' => null, 'title' => 'Architecture', 'content' => 'The login goes through the gateway', 'archived_at' => null, 'created_by' => $user, 'updated_by' => $user]);
    }

    public function testRoutesRequireAuthentication()
    {
        $this->get('/api/search?q=login')->assertUnauthorized();
    }

    public function testEveryTypeIsFoundInItsOrderAndTheUnfinishedFirst()
    {
        $user = $this->register();
        $this->fill(SelectedWorkspace::get(), $user);

        $results = $this->search('login');

        $this->assertSame(['merge_request', 'merge_request', 'jira_issue', 'task', 'quick_action', 'doc_page'], array_column($results, 'type'));
        $this->assertSame(['Login form', 'Login form, merged'], array_column(array_slice($results, 0, 2), 'title'));
        $this->assertSame([false, true], array_column(array_slice($results, 0, 2), 'done'));
        $this->assertSame('https://bitbucket.org/acme/app/pull-requests/2', $results[0]['url']);
        $this->assertSame('APP-42 · '.JiraIssue::findWhere(['issue_key' => 'APP-42'])->status, $results[2]['detail']);
        $this->assertSame(['APP-42'], array_map(fn (array $result) => explode(' · ', $result['detail'])[0], $this->search('app-4')));
    }

    public function testAHiddenSectionIsNotSearched()
    {
        $user = $this->register();
        $workspace = SelectedWorkspace::get();
        $this->fill($workspace, $user);
        $this->putJson("/api/workspaces/{$workspace}", ['name' => 'Pro', 'accent_color' => null, 'sections' => ['tasks', 'documentation']])->assertOk();

        $this->assertSame(['task', 'doc_page'], array_column($this->search('login'), 'type'));
    }

    public function testOnlyTheCurrentWorkspaceIsSearched()
    {
        $user = $this->register();
        $other = Workspace::fromArray(['name' => 'Other', 'author' => $user])->save();
        $this->fill($other->id, $user);

        $this->assertSame([], $this->search('login'));
    }

    public function testResultsAreLimitedPerType()
    {
        $this->register();
        for ($index = 0; $index < 7; $index++)
            Task::factory()->create(['workspace' => SelectedWorkspace::get(), 'sprint' => null, 'jira_issue' => null, 'title' => "Task {$index}", 'status' => 'todo']);

        $this->assertCount(5, $this->search('task'));
    }

    public function testTooShortATextIsRefused()
    {
        $this->register();

        $this->get('/api/search?q=a')->assertUnprocessableContent();
        $this->get('/api/search')->assertUnprocessableContent();
    }
}
