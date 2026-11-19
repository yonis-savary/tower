<?php

namespace Tests\Integration;

use App\Integrations\IntegrationService;
use App\Integrations\Jira\JiraClient;
use App\Integrations\Jira\JiraIssueSync;
use App\Models\History;
use App\Models\Integration;
use App\Models\JiraIssue;
use App\Models\JiraProfile;
use App\Models\Task;
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

class JiraTest extends CubeTestCase
{
    private const SITE = 'https://team.atlassian.net';

    /** @var array<string,array> */
    private array $issues = [];
    /** @var list<string> */
    private array $assigned = [];
    /** @var list<string> */
    private array $secondBoard = [];
    private ?array $refusal = null;
    private int $searchStatus = 200;
    private ?string $lastJql = null;
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

    private const BOARD = 7;
    private const SECOND_BOARD = 9;
    private const ME = ['accountId' => 'me-123', 'displayName' => 'Alice', 'avatarUrls' => ['24x24' => 'https://avatars.example/alice.png']];
    private const BOB = ['accountId' => 'bob-456', 'displayName' => 'Bob'];
    private const ATTACHMENTS = [
        '501' => ['id' => '501', 'filename' => 'mockup.png', 'mimeType' => 'image/png', 'size' => 4, 'created' => '2026-11-10T10:00:00.000+0100', 'author' => ['displayName' => 'Bob']],
        '502' => ['id' => '502', 'filename' => 'notes.txt', 'mimeType' => 'text/plain', 'size' => 11, 'created' => '2026-11-10T10:00:00.000+0100', 'author' => ['displayName' => 'Bob']],
        '503' => ['id' => '503', 'filename' => 'video.mp4', 'mimeType' => 'video/mp4', 'size' => 50000000, 'created' => '2026-11-10T10:00:00.000+0100', 'author' => ['displayName' => 'Bob']],
    ];
    private const ATTACHMENT_BODIES = ['501' => "\x89PNG", '502' => 'Hello there', '503' => ''];
    private const STATUSES = [
        '11' => ['id' => '11', 'name' => 'To Do', 'statusCategory' => ['key' => 'new']],
        '21' => ['id' => '21', 'name' => 'In Progress', 'statusCategory' => ['key' => 'indeterminate']],
        '22' => ['id' => '22', 'name' => 'In Review', 'statusCategory' => ['key' => 'indeterminate']],
        '23' => ['id' => '23', 'name' => 'Ready for review', 'statusCategory' => ['key' => 'indeterminate']],
        '31' => ['id' => '31', 'name' => 'Done', 'statusCategory' => ['key' => 'done']],
        '32' => ['id' => '32', 'name' => 'Fix', 'statusCategory' => ['key' => 'indeterminate']],
    ];

    private function fakeJira(): HttpMockServer
    {
        return HttpMockServer::fromArray([
            '/rest/api/3/myself' => fn () => Response::json(self::ME),
            '/rest/api/3/attachment/content/{id}' => fn (Request $request, string $id) => isset(self::ATTACHMENTS[$id])
                ? new Response(200, self::ATTACHMENT_BODIES[$id], ['Content-Type' => self::ATTACHMENTS[$id]['mimeType']])
                : Response::json(['errorMessages' => ['Attachment does not exist']], 404),
            '/rest/api/3/attachment/{id}' => fn (Request $request, string $id) => isset(self::ATTACHMENTS[$id])
                ? Response::json(self::ATTACHMENTS[$id])
                : Response::json(['errorMessages' => ['Attachment does not exist']], 404),
            '/rest/agile/1.0/board/{int:board}/issue' => function (Request $request, int $board) {
                $keys = [self::BOARD => $this->assigned, self::SECOND_BOARD => $this->secondBoard][$board] ?? null;
                if (200 !== $this->searchStatus || null === $keys)
                    return Response::json([], 200 === $this->searchStatus ? 404 : $this->searchStatus);

                $this->lastJql = $request->param('jql');
                $startAt = (int) $request->param('startAt', 0);
                $key = $keys[$startAt] ?? null;

                return Response::json(['startAt' => $startAt, 'total' => count($keys), 'issues' => $key ? [$this->issues[$key]] : []]);
            },
            '/rest/agile/1.0/board/{int:board}' => fn (Request $request, int $board) => in_array($board, [self::BOARD, self::SECOND_BOARD], true)
                ? Response::json(['id' => $board, 'name' => "Board {$board}"])
                : Response::json(['errorMessages' => ['Board does not exist']], 404),
            '/rest/agile/1.0/board/{int:board}/configuration' => fn () => Response::json(['columnConfig' => ['columns' => [
                ['name' => 'Backlog', 'statuses' => [['id' => '11']]],
                ['name' => 'In Progress', 'statuses' => [['id' => '21']]],
                ['name' => 'Review', 'statuses' => [['id' => '22'], ['id' => '23']]],
                ['name' => 'Done', 'statuses' => [['id' => '31'], ['id' => '32']]],
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
                'assignee' => self::ME,
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
        $integration = $this->putJson('/api/integrations/jira', [
            'site' => self::SITE,
            'email' => 'me@example.com',
            'token' => 'the-api-token',
            ...$override,
        ])->assertOk()->json();
        $this->addProfile(self::BOARD);

        return $integration;
    }

    private function addProfile(int $board, ?string $jql = null, ?string $name = null): int
    {
        return $this->postJson('/api/integrations/jira/profiles', ['board' => $board, 'jql' => $jql, 'name' => $name])->assertCreated()->json()['id'];
    }

    private function profiles(): array
    {
        return $this->get('/api/jira/profiles')->assertOk()->json()['profiles'];
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

        $this->putJson('/api/integrations/jira', ['site' => 'https://evil.example.com', 'email' => 'me@example.com', 'token' => 'x'])->assertUnprocessableContent();
        $this->putJson('/api/integrations/jira', ['site' => 'http://team.atlassian.net', 'email' => 'me@example.com', 'token' => 'x'])->assertUnprocessableContent();
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

        $columns = $this->profiles()[0]['columns'];
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
        $profile = $this->profiles()[0]['id'];

        $this->putJson("/api/integrations/jira/profiles/{$profile}", ['board' => 8])->assertOk();
        $this->assertSame([], $this->get('/api/jira/issues')->assertOk()->json());
        $this->assertSame([[], null], [$this->profiles()[0]['columns'], $this->profiles()[0]['board_name']]);
        $this->assertSame([], $this->historyTypes());

        $this->sync();
        $this->assertSame('Jira does not know the board 8', $this->integration()->last_error);
    }

    public function testAnotherSiteForgetsTheProfilesAnotherAccountKeepsThem()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1');
        $this->assigned = ['PLAT-1'];
        $this->sync();

        $this->putJson('/api/integrations/jira', ['site' => self::SITE, 'email' => 'other@example.com'])->assertOk();
        $this->assertSame([], $this->get('/api/jira/issues')->assertOk()->json());
        $this->assertCount(1, $this->profiles());

        $this->putJson('/api/integrations/jira', ['site' => 'https://other-team.atlassian.net', 'email' => 'other@example.com'])->assertOk();
        $this->assertSame([], $this->profiles());
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

    public function testTheWholeBoardIsShownButOnlyTheIssuesOfTheAccountAreAnnounced()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1');
        $this->issue('101', 'PLAT-2', ['assignee' => self::BOB]);
        $this->issue('102', 'PLAT-3', ['assignee' => null]);
        $this->assigned = ['PLAT-1', 'PLAT-2', 'PLAT-3'];
        $this->sync();

        $this->assertSame('me-123', $this->get('/api/jira/profiles')->assertOk()->json()['account_id']);
        $issues = array_column($this->get('/api/jira/issues')->assertOk()->json(), null, 'key');
        $this->assertSame(['me-123', 'Alice', 'https://avatars.example/alice.png'], [$issues['PLAT-1']['assignee_id'], $issues['PLAT-1']['assignee_name'], $issues['PLAT-1']['assignee_avatar']]);
        $this->assertSame('Bob', $issues['PLAT-2']['assignee_name']);
        $this->assertNull($issues['PLAT-3']['assignee_id']);

        $this->issue('101', 'PLAT-2', ['assignee' => self::BOB, 'status' => self::STATUSES['22'], 'summary' => 'Changed by Bob']);
        $this->issue('102', 'PLAT-3', ['assignee' => self::ME]);
        $this->issue('100', 'PLAT-1', ['assignee' => self::BOB]);
        $this->sync();

        $this->assertEqualsCanonicalizing(['jira.issue.assigned', 'jira.issue.unassigned'], $this->historyTypes());
        $unassigned = json_decode(History::findWhere(['type' => 'jira.issue.unassigned'])->details, true);
        $this->assertSame(['PLAT-1', 'Bob'], [$unassigned['key'], $unassigned['assignee']]);

        $this->assigned = ['PLAT-1', 'PLAT-3'];
        $this->sync();
        $this->assertCount(2, $this->historyTypes());
        $this->assertNull($this->stored('PLAT-2'));
    }

    public function testIssuesAreFilteredByAccountAndCategory()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1');
        $this->issue('101', 'PLAT-2', ['status' => self::STATUSES['21']]);
        $this->issue('102', 'PLAT-3', ['assignee' => self::BOB, 'status' => self::STATUSES['21']]);
        $this->assigned = ['PLAT-1', 'PLAT-2', 'PLAT-3'];
        $this->sync();

        $keys = fn (string $query) => array_column($this->get('/api/jira/issues'.$query)->assertOk()->json(), 'key');

        $this->assertEqualsCanonicalizing(['PLAT-1', 'PLAT-2', 'PLAT-3'], $keys(''));
        $this->assertEqualsCanonicalizing(['PLAT-1', 'PLAT-2'], $keys('?mine=true'));
        $this->assertEqualsCanonicalizing(['PLAT-2', 'PLAT-3'], $keys('?status_category=in_progress'));
        $this->assertSame(['PLAT-2'], $keys('?mine=true&status_category=in_progress'));
        $this->get('/api/jira/issues?status_category=closed')->assertUnprocessableContent();
    }

    public function testTheLastColumnOfTheBoardIsDone()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1');
        $this->assigned = ['PLAT-1'];
        $this->sync();
        $this->assertStringContainsString('status NOT IN (31, 32)', $this->lastJql);

        $this->issue('100', 'PLAT-1', ['status' => self::STATUSES['32']]);
        $this->assigned = [];
        $this->sync();

        $this->assertSame(['jira.issue.done'], $this->historyTypes());
        $this->assertSame('Fix', json_decode(History::findWhere(['type' => 'jira.issue.done'])->details, true)['status']);
    }

    public function testTheFirstSyncKnowingTheAccountIsANewReference()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1');
        $this->assigned = ['PLAT-1'];
        $this->sync();

        $integration = $this->integration();
        $settings = json_decode($integration->settings, true);
        unset($settings['account']);
        $integration->settings = json_encode($settings);
        $integration->save();
        $row = $this->stored('PLAT-1');
        $row->assignee_id = null;
        $row->save();

        $this->sync();
        $this->assertSame([], $this->historyTypes());
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

    public function testSubtasksAreStoredUnderTheirParentAndLeaveWithIt()
    {
        $this->register();
        $this->save();
        $subtask = fn (string $id, string $key, string $status) => ['id' => $id, 'key' => $key, 'fields' => ['summary' => "Subtask {$key}", 'status' => self::STATUSES[$status], 'issuetype' => ['name' => 'Sub-task'], 'priority' => ['name' => 'Low']]];
        $this->issue('100', 'PLAT-1', [
            'sprint' => ['id' => 7, 'name' => 'Sprint 7', 'state' => 'active'],
            'subtasks' => [$subtask('200', 'PLAT-10', '21'), $subtask('201', 'PLAT-11', '31')],
        ]);
        $this->assigned = ['PLAT-1'];
        $this->sync();
        $this->sync();

        $parent = $this->stored('PLAT-1');
        $issues = array_column($this->get('/api/jira/issues')->assertOk()->json(), null, 'key');
        $this->assertCount(3, $issues);
        $this->assertSame([$parent->id, 'Sprint 7', 'Platform', 'In Progress', null], [$issues['PLAT-10']['parent'], $issues['PLAT-10']['sprint_name'], $issues['PLAT-10']['project'], $issues['PLAT-10']['status'], $issues['PLAT-10']['assignee_id']]);
        $this->assertSame('done', $issues['PLAT-11']['status_category']);
        $this->assertNull($issues['PLAT-1']['parent']);
        $this->assertSame([], $this->historyTypes());

        // Read alone, a subtask has its own assignee and sprint : the transition only takes its status
        $this->issue('200', 'PLAT-10', ['sprint' => ['id' => 9, 'name' => 'Other', 'state' => 'future']]);
        $this->postJson("/api/jira/issues/{$issues['PLAT-10']['id']}/transitions", ['transition' => '22'])->assertOk();
        $moved = $this->stored('PLAT-10');
        $this->assertSame(['In Review', 'Sprint 7', null], [$moved->status, $moved->sprint_name, $moved->assignee_id]);
        $this->assertSame([], $this->historyTypes());

        $task = $this->postJson("/api/jira/issues/{$issues['PLAT-11']['id']}/task")->assertCreated()->json();
        $this->assigned = [];
        $this->sync();

        $this->assertNull($this->integration()->last_error);
        $this->assertNull($this->stored('PLAT-1'));
        $this->assertNull($this->stored('PLAT-10'));
        $kept = $this->stored('PLAT-11');
        $this->assertSame([false, null], [(bool) $kept->tracked, $kept->parent]);
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
        $this->assertSame(['11', '21', '22', '23', '31', '32'], array_column($transitions, 'status_id'));
        $this->assertSame(['todo', 'in_progress', 'in_progress', 'in_progress', 'done', 'in_progress'], array_column($transitions, 'status_category'));

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
        $this->assertSame([], $payload['attachments']);

        unset($this->issues['PLAT-1']);
        $this->postJson("/api/jira/issues/{$id}/discuss")->assertNotFound();
    }

    public function testAnIssueIsReadByItsKeyWithItsAttachments()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-9', ['labels' => ['billing'], 'attachment' => [self::ATTACHMENTS['501'], self::ATTACHMENTS['502']]]);

        $read = $this->get('/api/jira/issues/plat-9')->assertOk()->json();
        $this->assertSame('PLAT-9', $read['key']);
        $this->assertSame("h2. Context\nThe description of PLAT-9", $read['description']);
        $this->assertSame(['billing'], $read['labels']);
        $this->assertSame(['mockup.png', 'notes.txt'], array_column($read['attachments'], 'filename'));
        $this->assertSame(['501', 'image/png', 4, 'Bob'], [$read['attachments'][0]['id'], $read['attachments'][0]['mime_type'], $read['attachments'][0]['size'], $read['attachments'][0]['author']]);
        $this->assertNull($this->stored('PLAT-9'));

        $this->get('/api/jira/issues/PLAT-404')->assertNotFound();
    }

    public function testAnAttachmentIsDownloadedUnderTheSizeLimit()
    {
        $this->register();
        $this->save();

        $this->get('/api/jira/attachments/502')->assertOk()->assertHeaderEqual('content-type', 'text/plain');
        $this->assertSame('Hello there', $this->get('/api/jira/attachments/502')->body());
        $this->get('/api/jira/attachments/503')->assertResponseCode(413);
        $this->get('/api/jira/attachments/999')->assertNotFound();
    }

    public function testWithoutIntegrationThereIsNothingToRead()
    {
        $this->register();

        $this->get('/api/jira/issues/PLAT-1')->assertNotFound();
        $this->get('/api/jira/attachments/501')->assertNotFound();
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

    public function testProfilesAreManagedInTheirWorkspaceAndValidated()
    {
        $this->register();
        $this->postJson('/api/integrations/jira/profiles', ['board' => self::BOARD])->assertNotFound();
        $this->putJson('/api/integrations/jira', ['site' => self::SITE, 'email' => 'me@example.com', 'token' => 'the-api-token'])->assertOk();

        $id = $this->addProfile(self::BOARD, '  component = Billing  ', '  Billing  ');
        $this->assertSame(['Billing', self::BOARD, 'component = Billing'], [$this->profiles()[0]['name'], $this->profiles()[0]['board'], $this->profiles()[0]['jql']]);

        $updated = $this->putJson("/api/integrations/jira/profiles/{$id}", ['board' => self::BOARD, 'name' => '', 'jql' => ''])->assertOk()->json();
        $this->assertSame([null, null], [$updated['name'], $updated['jql']]);

        $this->postJson('/api/integrations/jira/profiles', ['board' => 0])->assertUnprocessableContent();
        $this->postJson('/api/integrations/jira/profiles', ['board' => self::BOARD, 'jql' => 'project = PLAT order  by created'])->assertUnprocessableContent();
        $this->postJson('/api/integrations/jira/profiles', ['board' => self::BOARD, 'name' => str_repeat('x', 101)])->assertUnprocessableContent();

        Authentication::getInstance()->logout();
        SelectedWorkspace::unset();
        $this->register('bob@example.com');
        $this->assertSame([], $this->get('/api/jira/profiles')->assertOk()->json()['profiles']);
        $this->putJson("/api/integrations/jira/profiles/{$id}", ['board' => self::BOARD])->assertNotFound();
        $this->delete("/api/integrations/jira/profiles/{$id}")->assertNotFound();
        $this->assertNotNull(JiraProfile::find($id));
    }

    public function testAnIssueOnTwoProfilesIsStoredOnceAndFilteredByProfile()
    {
        $this->register();
        $this->save();
        $second = $this->addProfile(self::SECOND_BOARD, 'component = Billing');
        $first = $this->profiles()[0]['id'];
        $this->issue('100', 'PLAT-1');
        $this->issue('101', 'PLAT-2');
        $this->assigned = ['PLAT-1'];
        $this->secondBoard = ['PLAT-1', 'PLAT-2'];

        $this->sync();

        $this->assertNull($this->integration()->last_error);
        $this->assertStringStartsWith('(component = Billing) AND statusCategory != Done', $this->lastJql);
        $this->assertSame(['Board 7', 'Board 9'], array_column($this->profiles(), 'board_name'));
        $issues = array_column($this->get('/api/jira/issues')->assertOk()->json(), 'profiles', 'key');
        $this->assertEqualsCanonicalizing([$first, $second], $issues['PLAT-1']);
        $this->assertSame([$second], $issues['PLAT-2']);
        $this->assertSame(['PLAT-1'], array_column($this->get("/api/jira/issues?profile={$first}")->assertOk()->json(), 'key'));
        $this->assertEqualsCanonicalizing(['PLAT-1', 'PLAT-2'], array_column($this->get("/api/jira/issues?profile={$second}")->assertOk()->json(), 'key'));

        $this->secondBoard = ['PLAT-2'];
        $this->sync();
        $this->assertSame([], $this->historyTypes());
        $this->assertSame([$first], array_column($this->get('/api/jira/issues')->assertOk()->json(), 'profiles', 'key')['PLAT-1']);

        $this->issue('100', 'PLAT-1', ['status' => self::STATUSES['31']]);
        $this->assigned = [];
        $this->sync();
        $this->assertSame(['jira.issue.done'], $this->historyTypes());
    }

    public function testANewProfileIsItsOwnReference()
    {
        $this->register();
        $this->save();
        $this->issue('100', 'PLAT-1');
        $this->assigned = ['PLAT-1'];
        $this->sync();

        $this->addProfile(self::SECOND_BOARD);
        $this->issue('101', 'PLAT-2');
        $this->secondBoard = ['PLAT-2'];
        $this->sync();
        $this->assertSame([], $this->historyTypes());

        $this->issue('102', 'PLAT-3');
        $this->secondBoard = ['PLAT-2', 'PLAT-3'];
        $this->sync();
        $this->assertSame(['jira.issue.assigned'], $this->historyTypes());
    }

    public function testDeletingAProfileForgetsOnlyTheIssuesNoOtherProfileShows()
    {
        $this->register();
        $this->save();
        $second = $this->addProfile(self::SECOND_BOARD);
        $this->issue('100', 'PLAT-1');
        $this->issue('101', 'PLAT-2');
        $this->issue('102', 'PLAT-3');
        $this->assigned = ['PLAT-1'];
        $this->secondBoard = ['PLAT-1', 'PLAT-2', 'PLAT-3'];
        $this->sync();
        $task = $this->postJson("/api/jira/issues/{$this->stored('PLAT-3')->id}/task")->assertCreated()->json();

        $this->delete("/api/integrations/jira/profiles/{$second}")->assertNoContent();

        $this->assertSame(['PLAT-1'], array_column(array_filter($this->get('/api/jira/issues')->assertOk()->json(), fn (array $issue) => $issue['tracked']), 'key'));
        $this->assertNull($this->stored('PLAT-2'));
        $kept = $this->stored('PLAT-3');
        $this->assertSame([false, $kept->id], [(bool) $kept->tracked, Task::find($task['id'])->jira_issue]);
        $this->assertNull(JiraProfile::find($second));

        $this->sync();
        $this->assertSame(['task.created'], $this->historyTypes());
    }

    public function testASubtaskListedOnItsOwnIsStoredOnceUnderItsParent()
    {
        $this->register();
        $this->save();
        $subtask = ['id' => '200', 'key' => 'PLAT-10', 'fields' => ['summary' => 'Subtask PLAT-10', 'status' => self::STATUSES['21'], 'issuetype' => ['name' => 'Sub-task'], 'priority' => ['name' => 'Low']]];
        $this->issue('200', 'PLAT-10', ['issuetype' => ['name' => 'Sub-task']]);
        $this->issue('100', 'PLAT-1', ['subtasks' => [$subtask]]);
        $this->assigned = ['PLAT-10', 'PLAT-1'];

        $this->sync();
        $this->sync();

        $this->assertNull($this->integration()->last_error);
        $this->assertSame(1, JiraIssue::select()->where('external_id', '200')->count());
        $this->assertSame($this->stored('PLAT-1')->id, $this->stored('PLAT-10')->parent);
        $this->assertSame([], $this->historyTypes());
    }
}
