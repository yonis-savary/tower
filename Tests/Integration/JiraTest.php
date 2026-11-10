<?php

namespace Tests\Integration;

use App\Integrations\IntegrationService;
use App\Integrations\Jira\JiraClient;
use App\Integrations\Jira\JiraIssueSync;
use App\Models\History;
use App\Models\Integration;
use App\Models\JiraIssue;
use App\Models\Task;
use App\Session\SelectedWorkspace;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;
use Cube\Web\Http\HttpMockServer;
use Cube\Web\Http\MockServers;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Websocket\Broadcast\Broadcast;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;

class JiraTest extends CubeTestCase
{
    private const SITE = 'https://team.atlassian.net';

    /** @var array<string,array> */
    private array $issues = [];
    /** @var list<string> */
    private array $assigned = [];
    private ?array $refusal = null;
    private int $searchStatus = 200;
    private Broadcast $originalBroadcast;
    private object $broadcast;

    protected function setUp(): void
    {
        parent::setUp();
        Authentication::getInstance()->logout();
        SelectedWorkspace::unset();
        MockServers::getInstance()->set(JiraClient::class, $this->fakeJira());

        // Never reach the real websocket server
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

    private const BOARD = 7;
    private const STATUSES = [
        '11' => ['id' => '11', 'name' => 'To Do', 'statusCategory' => ['key' => 'new']],
        '21' => ['id' => '21', 'name' => 'In Progress', 'statusCategory' => ['key' => 'indeterminate']],
        '22' => ['id' => '22', 'name' => 'In Review', 'statusCategory' => ['key' => 'indeterminate']],
        '23' => ['id' => '23', 'name' => 'Ready for review', 'statusCategory' => ['key' => 'indeterminate']],
        '31' => ['id' => '31', 'name' => 'Done', 'statusCategory' => ['key' => 'done']],
    ];

    private function fakeJira(): HttpMockServer
    {
        return HttpMockServer::fromArray([
            '/rest/agile/1.0/board/{int:board}/issue' => function (Request $request, int $board) {
                if (200 !== $this->searchStatus || self::BOARD !== $board)
                    return Response::json([], 200 === $this->searchStatus ? 404 : $this->searchStatus);

                $startAt = (int) $request->param('startAt', 0);
                $key = $this->assigned[$startAt] ?? null;

                return Response::json(['startAt' => $startAt, 'total' => count($this->assigned), 'issues' => $key ? [$this->issues[$key]] : []]);
            },
            '/rest/agile/1.0/board/{int:board}/configuration' => fn () => Response::json(['columnConfig' => ['columns' => [
                ['name' => 'Backlog', 'statuses' => [['id' => '11']]],
                ['name' => 'In Progress', 'statuses' => [['id' => '21']]],
                ['name' => 'Review', 'statuses' => [['id' => '22'], ['id' => '23']]],
                ['name' => 'Done', 'statuses' => [['id' => '31']]],
            ]]]),
            '/rest/agile/1.0/issue/{key}' => fn (Request $request, string $key) => isset($this->issues[$key])
                ? Response::json($this->issues[$key])
                : Response::json(['errorMessages' => ['Issue does not exist']], 404),
            '/rest/api/2/issue/{key}' => fn (Request $request, string $key) => Response::json(['fields' => [
                'description' => "h2. Context\nThe description of {$key}",
                'comment' => ['comments' => [
                    ['author' => ['displayName' => 'Bob'], 'created' => '2026-11-10T10:00:00.000+0100', 'body' => 'First remark'],
                ]],
            ]]),
            '/rest/api/3/issue/{key}/transitions' => function (Request $request, string $key) {
                if ('POST' !== $request->getMethod()) {
                    return Response::json(['transitions' => array_map(
                        fn (array $status) => ['id' => $status['id'], 'name' => "Go {$status['name']}", 'to' => $status],
                        array_values(self::STATUSES),
                    )]);
                }

                if ($this->refusal)
                    return Response::json($this->refusal, 400);

                $this->issues[$key]['fields']['status'] = self::STATUSES[$request->getJSON()['transition']['id']];

                return Response::noContent();
            },
        ]);
    }

    private function issue(string $id, string $key, array $fields = []): void
    {
        $this->issues[$key] = [
            'id' => $id,
            'key' => $key,
            'fields' => [
                'summary' => "Issue {$key}",
                'status' => self::STATUSES['11'],
                'issuetype' => ['name' => 'Story'],
                'priority' => ['name' => 'Medium'],
                'project' => ['name' => 'Platform'],
                ...$fields,
            ],
        ];
    }

    private function register(string $email = 'alice@example.com'): void
    {
        $this->postJson('/api/auth/register', ['email' => $email, 'password' => 'correct horse battery'])->assertCreated();
    }

    private function save(array $override = []): array
    {
        return $this->putJson('/api/integrations/jira', [
            'site' => self::SITE,
            'email' => 'me@example.com',
            'board' => self::BOARD,
            'token' => 'the-api-token',
            ...$override,
        ])->assertOk()->json();
    }

    private function integration(): Integration
    {
        return Integration::findWhere(['type' => IntegrationService::TYPE_JIRA]);
    }

    private function sync(): void
    {
        inject(JiraIssueSync::class)->sync($this->integration());
    }

    private function stored(string $key): ?JiraIssue
    {
        return JiraIssue::findWhere(['issue_key' => $key]);
    }

    private function historyTypes(): array
    {
        return array_column($this->get('/api/history')->assertOk()->json(), 'type');
    }

    public function testTokenIsEncryptedAndOnlyJiraCloudIsAccepted()
    {
        $this->register();
        $body = $this->save();

        $this->assertStringNotContainsString('the-api-token', json_encode($body));
        $this->assertStringNotContainsString('the-api-token', $this->integration()->credentials);
        $this->assertSame(self::SITE, $this->get('/api/integrations/jira')->assertOk()->json()['site']);

        $this->putJson('/api/integrations/jira', ['site' => 'https://evil.example.com', 'email' => 'me@example.com', 'board' => 1, 'token' => 'x'])->assertUnprocessableContent();
        $this->putJson('/api/integrations/jira', ['site' => 'http://team.atlassian.net', 'email' => 'me@example.com', 'board' => 1, 'token' => 'x'])->assertUnprocessableContent();
        $this->putJson('/api/integrations/jira', ['site' => self::SITE, 'email' => 'me@example.com', 'board' => 0])->assertUnprocessableContent();
    }

    public function testAssignedIssuesAreReadPageByPageAndTranslated()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1', ['priority' => null]);
        $this->issue('101', 'PLAT-2', ['status' => self::STATUSES['22'], 'sprint' => ['id' => 5, 'name' => 'Sprint 12', 'state' => 'active']]);
        $this->assigned = ['PLAT-1', 'PLAT-2'];

        $this->sync();

        $issues = $this->get('/api/jira/issues')->assertOk()->json();
        $this->assertEqualsCanonicalizing(['PLAT-1', 'PLAT-2'], array_column($issues, 'key'));

        $second = $this->stored('PLAT-2');
        $this->assertSame('in_progress', $second->status_category);
        $this->assertSame('In Review', $second->status);
        $this->assertSame('22', $second->status_id);
        $this->assertSame(['5', 'Sprint 12', 'active'], [$second->sprint_id, $second->sprint_name, $second->sprint_state]);
        $this->assertNull($this->stored('PLAT-1')->sprint_id);
        $this->assertSame(self::SITE.'/browse/PLAT-2', $second->url);
        $this->assertNull($this->stored('PLAT-1')->priority);

        $columns = $this->get('/api/jira/columns')->assertOk()->json();
        $this->assertSame(['Backlog', 'In Progress', 'Review', 'Done'], array_column($columns, 'name'));
        $this->assertSame(['22', '23'], $columns[2]['statuses']);
    }

    public function testAnotherBoardStartsFromANewReference()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1');
        $this->assigned = ['PLAT-1'];
        $this->sync();

        $this->putJson('/api/integrations/jira', ['site' => self::SITE, 'email' => 'me@example.com', 'board' => 8])->assertOk();
        $this->assertSame([], $this->get('/api/jira/issues')->assertOk()->json());
        $this->assertSame([], $this->get('/api/jira/columns')->assertOk()->json());

        $this->sync();
        $this->assertSame('Jira does not know the board 8', $this->integration()->last_error);
    }

    public function testFirstSyncIsTheReferenceThenDifferencesAreLogged()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1');
        $this->assigned = ['PLAT-1'];

        $this->sync();
        $this->assertSame([], $this->historyTypes());

        $this->issue('100', 'PLAT-1', ['summary' => 'Renamed']);
        $this->issue('101', 'PLAT-2');
        $this->assigned = ['PLAT-1', 'PLAT-2'];
        $this->sync();
        $this->assertEqualsCanonicalizing(['jira.issue.assigned', 'jira.issue.updated'], $this->historyTypes());

        $this->issue('100', 'PLAT-1', ['status' => self::STATUSES['31']]);
        $this->assigned = ['PLAT-2'];
        $this->sync();

        $this->assertSame('jira.issue.done', $this->historyTypes()[0]);
        $this->assertNull($this->stored('PLAT-1'));

        $this->assigned = [];
        $this->sync();
        $this->assertSame('jira.issue.unassigned', $this->historyTypes()[0]);
    }

    public function testEachChangeIsAPreciseEventSentToTheDevices()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1');
        $this->assigned = ['PLAT-1'];
        $this->sync();

        $this->issue('100', 'PLAT-1', [
            'status' => self::STATUSES['22'],
            'priority' => ['name' => 'High'],
            'sprint' => ['id' => 5, 'name' => 'Sprint 12', 'state' => 'active'],
        ]);
        $this->sync();

        $this->assertEqualsCanonicalizing(['jira.issue.status_changed', 'jira.issue.priority_changed', 'jira.issue.sprint_changed'], $this->historyTypes());
        $lines = array_column($this->get('/api/history')->assertOk()->json(), 'details', 'type');
        $this->assertSame([
            'source' => 'jira',
            'key' => 'PLAT-1',
            'summary' => 'Issue PLAT-1',
            'url' => self::SITE.'/browse/PLAT-1',
            'from' => 'To Do',
            'to' => 'In Review',
            'from_category' => 'todo',
            'to_category' => 'in_progress',
        ], json_decode($lines['jira.issue.status_changed'], true));
        $this->assertSame(['from' => null, 'to' => 'Sprint 12'], array_intersect_key(json_decode($lines['jira.issue.sprint_changed'], true), ['from' => 0, 'to' => 0]));
        $this->assertEqualsCanonicalizing($this->historyTypes(), array_column($this->broadcast->emitted, 'event'));
    }

    public function testAnIssueWithATaskIsKeptOffTheBoard()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1');
        $this->assigned = ['PLAT-1'];
        $this->sync();

        $issue = $this->stored('PLAT-1');
        $task = $this->postJson("/api/jira/issues/{$issue->id}/task")->assertCreated()->json();
        $this->assertSame($issue->id, $task['jira_issue']);
        $this->assertSame('PLAT-1 Issue PLAT-1', $task['title']);
        $this->postJson("/api/jira/issues/{$issue->id}/task")->assertConflict();

        $this->issue('100', 'PLAT-1', ['status' => self::STATUSES['31']]);
        $this->assigned = [];
        $this->sync();

        $kept = $this->stored('PLAT-1');
        $this->assertFalse((bool) $kept->tracked);
        $this->assertSame('done', $kept->status_category);
        $this->assertSame($kept->id, Task::find($task['id'])->jira_issue);
    }

    public function testFailureKeepsTheStoredStateAndReportsTheError()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1');
        $this->assigned = ['PLAT-1'];
        $this->sync();

        $this->searchStatus = 401;
        $this->sync();

        $this->assertSame('Jira answered 401 on /rest/agile/1.0/board/7/issue', $this->integration()->last_error);
        $this->assertNotNull($this->stored('PLAT-1'));
    }

    public function testTransitionsAreListedAndApplied()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1');
        $this->assigned = ['PLAT-1'];
        $this->sync();
        $id = $this->stored('PLAT-1')->id;

        $transitions = $this->get("/api/jira/issues/{$id}/transitions")->assertOk()->json();
        $this->assertSame(['11', '21', '22', '23', '31'], array_column($transitions, 'status_id'));
        $this->assertSame(['todo', 'in_progress', 'in_progress', 'in_progress', 'done'], array_column($transitions, 'status_category'));

        $moved = $this->postJson("/api/jira/issues/{$id}/transitions", ['transition' => '22'])->assertOk()->json();
        $this->assertSame('In Review', $moved['status']);
        $this->assertSame('in_progress', $moved['status_category']);
        $this->assertSame('jira.issue.status_changed', $this->historyTypes()[0]);
        $this->assertSame('user', History::findWhere(['type' => 'jira.issue.status_changed'])->origin);
        $this->assertSame(['jira.issue.status_changed'], array_column($this->broadcast->emitted, 'event'));

        $this->sync();
        $this->assertCount(1, $this->historyTypes());

        $this->refusal = ['errorMessages' => [], 'errors' => ['resolution' => 'Resolution is required.']];
        $response = $this->postJson("/api/jira/issues/{$id}/transitions", ['transition' => '31'])->assertUnprocessableContent();
        $this->assertSame('Resolution is required.', $response->json()['error']);
        $this->assertSame('In Review', $this->stored('PLAT-1')->status);
    }

    public function testDiscussionSendsTheIssueReadFromJira()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1', ['labels' => ['backend'], 'assignee' => ['displayName' => 'Alice']]);
        $this->assigned = ['PLAT-1'];
        $this->sync();
        $id = $this->stored('PLAT-1')->id;

        $this->issue('100', 'PLAT-1', ['summary' => 'Changed in Jira since the sync', 'labels' => ['backend']]);
        $this->postJson("/api/jira/issues/{$id}/discuss")->assertNoContent();

        $this->assertSame('ai.discuss', $this->broadcast->emitted[0]['event']);
        $payload = json_decode(History::findWhere(['type' => 'event.triggered'])->details, true)['payload'];
        $this->assertSame('Changed in Jira since the sync', $payload['summary']);
        $this->assertSame("h2. Context\nThe description of PLAT-1", $payload['description']);
        $this->assertSame(['backend'], $payload['labels']);
        $this->assertSame([['author' => 'Bob', 'created' => '2026-11-10T10:00:00.000+0100', 'body' => 'First remark']], $payload['comments']);

        unset($this->issues['PLAT-1']);
        $this->postJson("/api/jira/issues/{$id}/discuss")->assertNotFound();
    }

    public function testIssuesStayInTheirWorkspace()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1');
        $this->assigned = ['PLAT-1'];
        $this->sync();
        $id = $this->stored('PLAT-1')->id;

        Authentication::getInstance()->logout();
        SelectedWorkspace::unset();
        $this->register('bob@example.com');

        $this->assertSame([], $this->get('/api/jira/issues')->assertOk()->json());
        $this->get("/api/jira/issues/{$id}/transitions")->assertNotFound();
        $this->postJson("/api/jira/issues/{$id}/task")->assertNotFound();
        $this->postJson("/api/jira/issues/{$id}/discuss")->assertNotFound();
        $this->get('/api/integrations/jira')->assertNotFound();
    }

    public function testDeletingTheIntegrationUnlinksTheTasks()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1');
        $this->assigned = ['PLAT-1'];
        $this->sync();
        $task = $this->postJson("/api/jira/issues/{$this->stored('PLAT-1')->id}/task")->assertCreated()->json();

        $this->delete('/api/integrations/jira')->assertNoContent();

        $this->assertNull($this->stored('PLAT-1'));
        $this->assertNull(Task::find($task['id'])->jira_issue);
    }
}
