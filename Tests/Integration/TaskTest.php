<?php

namespace Tests\Integration;

use App\Domain\Workspace\WorkspaceSignals;
use App\Models\History;
use App\Models\Task;
use App\Session\SelectedWorkspace;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;
use Cube\Web\Websocket\Broadcast\Broadcast;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;

class TaskTest extends CubeTestCase
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

    private function logOut(): void
    {
        Authentication::getInstance()->logout();
        SelectedWorkspace::unset();
    }

    private function register(string $email = 'alice@example.com'): void
    {
        $this->postJson('/api/auth/register', ['email' => $email, 'password' => 'correct horse battery'])->assertCreated();
    }

    private function task(string $title, ?int $sprint = null): array
    {
        return $this->postJson('/api/tasks', ['title' => $title, 'sprint' => $sprint])->assertCreated()->json();
    }

    private function sprint(string $name = 'Sprint 1'): array
    {
        return $this->postJson('/api/sprints', ['name' => $name])->assertCreated()->json();
    }

    private function move(array $task, ?int $sprint, string $status, ?array $ordered = null)
    {
        return $this->putJson("/api/tasks/{$task['id']}/move", ['sprint' => $sprint, 'status' => $status, ...(null === $ordered ? [] : ['ordered' => $ordered])]);
    }

    private function titles(): array
    {
        return array_column($this->get('/api/tasks')->assertOk()->json(), 'title');
    }

    public function testRoutesRequireAuthentication()
    {
        $this->get('/api/tasks')->assertUnauthorized();
        $this->get('/api/sprints')->assertUnauthorized();
    }

    public function testNewTaskGoesAtTheEndOfTheBacklog()
    {
        $this->register();
        $first = $this->task('First');
        $second = $this->task('Second');

        $this->assertSame('todo', $first['status']);
        $this->assertNull($first['sprint']);
        $this->assertSame($first['position'] + 1, $second['position']);
        $this->assertSame(['First', 'Second'], $this->titles());
    }

    public function testTasksMoveBetweenAndInsideColumns()
    {
        $this->register();
        $sprint = $this->sprint();
        $a = $this->task('A', $sprint['id']);
        $b = $this->task('B', $sprint['id']);

        $moved = $this->move($b, $sprint['id'], 'in_review', [$b['id'], $a['id']])->assertOk()->json();
        $this->assertSame('in_review', $moved['status']);
        $this->assertSame(['B', 'A'], $this->titles());

        $this->move($a, $sprint['id'], 'in_review', [$a['id'], $b['id']])->assertOk();
        $this->assertSame(['A', 'B'], $this->titles());
    }

    public function testSeveralActiveSprintsAndClosingKeepsTheTasks()
    {
        $this->register();
        $first = $this->sprint('One');
        $second = $this->sprint('Two');
        $planned = $this->sprint('Three');
        $task = $this->task('Unfinished', $first['id']);

        $this->assertSame('active', $this->postJson("/api/sprints/{$first['id']}/open", [])->assertOk()->json()['state']);
        $this->assertSame('active', $this->postJson("/api/sprints/{$second['id']}/open", [])->assertOk()->json()['state']);
        $this->postJson("/api/sprints/{$first['id']}/open", [])->assertOk();

        $this->postJson("/api/sprints/{$planned['id']}/close", [])->assertConflict();
        $this->assertSame('closed', $this->postJson("/api/sprints/{$first['id']}/close", [])->assertOk()->json()['state']);

        $stored = $this->get('/api/tasks')->assertOk()->json()[0];
        $this->assertSame($first['id'], $stored['sprint']);
        $this->assertSame('todo', $stored['status']);

        $this->assertSame(['closed', 'active', 'planned'], array_column($this->get('/api/sprints')->assertOk()->json(), 'state'));
        $this->move($task, $second['id'], 'todo')->assertOk();
        $this->move($task, $first['id'], 'todo')->assertUnprocessableContent();
        $this->postJson('/api/tasks', ['title' => 'Late', 'sprint' => $first['id']])->assertUnprocessableContent();
    }

    public function testHistoryTellsWhoChangedTheStatus()
    {
        $this->register();
        $task = $this->task('Tracked');

        $this->move($task, null, 'in_progress')->assertOk();
        $this->putJson("/api/tasks/{$task['id']}", ['title' => 'Renamed', 'description' => 'Why'])->assertOk();

        $lines = $this->get("/api/tasks/{$task['id']}/history")->assertOk()->json();
        $this->assertSame(['task.updated', 'task.status_changed', 'task.created'], array_column($lines, 'type'));
        $this->assertSame('alice@example.com', $lines[1]['author']);
        $this->assertSame('in_progress', json_decode($lines[1]['details'], true)['to']);
    }

    public function testChangesAreSentToTheDevices()
    {
        $this->register();
        $sprint = $this->sprint();
        $task = $this->task('Deploy', $sprint['id']);
        $this->move($task, $sprint['id'], 'in_progress')->assertOk();

        $this->assertSame(['sprint.created', 'task.created', 'task.status_changed'], array_column($this->broadcast->emitted, 'event'));

        $sent = end($this->broadcast->emitted);
        $this->assertSame('event', $sent['type']);
        $payload = json_decode($sent['payload'], true);
        $this->assertSame($task['id'], $payload['task']);
        $this->assertSame('Deploy', $payload['title']);
        $this->assertSame($sprint['id'], $payload['sprint']);
        $this->assertSame(['todo', 'in_progress'], [$payload['from'], $payload['to']]);
    }

    public function testATaskKeepsASingleLink()
    {
        $this->register();
        $task = $this->postJson('/api/tasks', ['title' => 'Spec', 'link' => 'https://example.com/spec'])->assertCreated()->json();
        $this->assertSame('https://example.com/spec', $task['link']);
        $this->assertNull($this->task('No link')['link']);

        // Left out, the link is kept; null removes it
        $this->assertSame('https://example.com/spec', $this->putJson("/api/tasks/{$task['id']}", ['title' => 'Spec v2'])->assertOk()->json()['link']);
        $this->assertSame('https://example.com/v3', $this->putJson("/api/tasks/{$task['id']}", ['title' => 'Spec', 'link' => 'https://example.com/v3'])->assertOk()->json()['link']);
        $this->assertNull($this->putJson("/api/tasks/{$task['id']}", ['title' => 'Spec', 'link' => null])->assertOk()->json()['link']);

        $this->postJson('/api/tasks', ['title' => 'Bad', 'link' => 'javascript:alert(1)'])->assertUnprocessableContent();
        $this->putJson("/api/tasks/{$task['id']}", ['title' => 'Bad', 'link' => 'not an address'])->assertUnprocessableContent();
    }

    public function testInvalidValuesAreRefused()
    {
        $this->register();
        $task = $this->task('Task');

        $this->postJson('/api/tasks', ['title' => ''])->assertUnprocessableContent();
        $this->move($task, null, 'nope')->assertUnprocessableContent();
        $this->move($task, null, 'todo', [])->assertUnprocessableContent();
        $this->move($task, 999, 'in_progress')->assertUnprocessableContent();
    }

    public function testEverythingStaysInItsWorkspace()
    {
        $this->register();
        $task = $this->task('Private');
        $sprint = $this->sprint();

        $this->logOut();
        $this->register('bob@example.com');
        $own = $this->task('Own');

        $this->assertSame(['Own'], $this->titles());
        $this->assertSame([], $this->get('/api/sprints')->assertOk()->json());
        $this->move($task, null, 'done')->assertNotFound();
        $this->get("/api/tasks/{$task['id']}/history")->assertNotFound();
        $this->postJson("/api/sprints/{$sprint['id']}/open", [])->assertNotFound();
        $this->move($own, $sprint['id'], 'todo')->assertUnprocessableContent();
        $this->move($own, null, 'todo', [$own['id'], $task['id']])->assertUnprocessableContent();
    }

    public function testSprintLinksAreKeptWhileTheSprintIsOpen()
    {
        $this->register();
        $sprint = $this->sprint();

        $board = $this->postJson("/api/sprints/{$sprint['id']}/links", ['url' => 'https://team.atlassian.net/board', 'label' => 'Board'])->assertCreated()->json();
        $this->postJson("/api/sprints/{$sprint['id']}/links", ['url' => 'https://figma.com/design/abc', 'label' => ''])->assertCreated();
        $this->postJson("/api/sprints/{$sprint['id']}/links", ['url' => 'javascript:alert(1)'])->assertUnprocessableContent();
        $this->postJson("/api/sprints/{$sprint['id']}/links", ['url' => 'ftp://files.example.com/spec'])->assertUnprocessableContent();
        $this->postJson('/api/sprints/999/links', ['url' => 'https://example.com'])->assertNotFound();

        $links = $this->get('/api/sprints')->assertOk()->json()[0]['links'];
        $this->assertSame([['id' => $board['id'], 'url' => 'https://team.atlassian.net/board', 'label' => 'Board'], ['id' => $board['id'] + 1, 'url' => 'https://figma.com/design/abc', 'label' => null]], $links);

        $this->putJson("/api/sprints/links/{$board['id']}", ['url' => 'https://team.atlassian.net/board/2', 'label' => 'Board 2'])->assertOk();
        $this->delete('/api/sprints/links/'.($board['id'] + 1))->assertNoContent();
        $this->assertSame([['id' => $board['id'], 'url' => 'https://team.atlassian.net/board/2', 'label' => 'Board 2']], $this->get('/api/sprints')->assertOk()->json()[0]['links']);

        $this->postJson("/api/sprints/{$sprint['id']}/open", [])->assertOk();
        $this->postJson("/api/sprints/{$sprint['id']}/close", [])->assertOk();
        $this->postJson("/api/sprints/{$sprint['id']}/links", ['url' => 'https://example.com'])->assertConflict();
        $this->putJson("/api/sprints/links/{$board['id']}", ['url' => 'https://example.com'])->assertConflict();
        $this->delete("/api/sprints/links/{$board['id']}")->assertConflict();

        $events = array_column($this->broadcast->emitted, 'event');
        $this->assertSame(['sprint.link_added', 'sprint.link_added', 'sprint.link_updated', 'sprint.link_removed'], array_values(array_filter($events, fn ($event) => str_starts_with($event, 'sprint.link'))));
        $this->assertSame('Board 2', json_decode($this->broadcast->emitted[3]['payload'], true)['link']);

        $this->logOut();
        $this->register('bob@example.com');
        $this->putJson("/api/sprints/links/{$board['id']}", ['url' => 'https://example.com'])->assertNotFound();
        $this->get("/api/sprints/links/{$board['id']}/icon")->assertNotFound();
    }

    public function testNotesRoutesRequireAuthentication()
    {
        $this->postJson('/api/tasks/notes/preview', ['text' => 'Task'])->assertUnauthorized();
        $this->postJson('/api/tasks/notes', ['text' => 'Task'])->assertUnauthorized();
    }

    public function testNotesPreviewCreatesNothing()
    {
        $this->register();

        $preview = $this->postJson('/api/tasks/notes/preview', ['text' => "\tsans tâche\nPréparer la démo\n\t- slides"])->assertOk()->json();

        $this->assertSame([['line' => 2, 'title' => 'Préparer la démo', 'description' => '- slides']], $preview['tasks']);
        $this->assertSame([['line' => 1, 'error' => 'description_without_task']], $preview['errors']);
        $this->assertSame([], $this->titles());
    }

    public function testNotesCreateEveryTaskInOrderAtTheEndOfTheSprint()
    {
        $this->register();
        $sprint = $this->sprint();
        $this->task('Already there', $sprint['id']);

        $created = $this->postJson('/api/tasks/notes', ['text' => "First\n\tdetails\n// ignored\nSecond", 'sprint' => $sprint['id']])->assertCreated()->json();

        $this->assertSame(['First', 'Second'], array_column($created, 'title'));
        $this->assertSame(['details', ''], array_column($created, 'description'));
        $this->assertSame([$sprint['id'], $sprint['id']], array_column($created, 'sprint'));
        $this->assertSame(['todo', 'todo'], array_column($created, 'status'));
        $this->assertSame(['Already there', 'First', 'Second'], $this->titles());
        $this->assertSame(['sprint.created', 'task.created', 'task.created', 'task.created'], array_column($this->broadcast->emitted, 'event'));
    }

    public function testNotesWithAnErrorOrWithoutTaskCreateNothing()
    {
        $this->register();

        $refused = $this->postJson('/api/tasks/notes', ['text' => "Fine\n" . str_repeat('t', 256)])->assertUnprocessableContent()->json();
        $this->assertSame([['line' => 2, 'error' => 'title_too_long']], $refused['errors']);

        $this->postJson('/api/tasks/notes', ['text' => "// only a note"])->assertUnprocessableContent();
        $this->assertSame([], $this->titles());
    }

    public function testNotesIntoAClosedSprintCreateNothing()
    {
        $this->register();
        $sprint = $this->sprint();
        $this->postJson("/api/sprints/{$sprint['id']}/open", [])->assertOk();
        $this->postJson("/api/sprints/{$sprint['id']}/close", [])->assertOk();

        $this->postJson('/api/tasks/notes', ['text' => 'Late', 'sprint' => $sprint['id']])->assertUnprocessableContent();
        $this->assertSame([], $this->titles());
    }

    private function timeline(string $from, string $to): array
    {
        return $this->get("/api/tasks/timeline?from={$from}&to={$to}")->assertOk()->json();
    }

    public function testTimelineListsTheTasksWorkedOnDuringThePeriod()
    {
        $this->register();
        $today = date('Y-m-d');
        $weekAgo = date('Y-m-d', strtotime('-7 days'));

        $done = $this->task('Done this week');
        $this->move($done, null, 'in_progress')->assertOk();
        $this->move($done, null, 'done')->assertOk();
        $this->task('Never started');
        $ongoing = $this->task('Started last month');
        $this->move($ongoing, null, 'in_review')->assertOk();
        $old = $this->task('Done long ago');
        $this->move($old, null, 'done')->assertOk();

        // Older than the history : placed by its creation and its completion only
        History::delete()->where('task', $old['id'])->fetch();
        Task::update()->set('created_at', '2026-01-05 09:00:00')->set('done_at', '2026-01-20 17:00:00')->where('id', $old['id'])->fetch();
        History::update()->set('created_at', date('Y-m-d H:i:s', strtotime('-40 days')))->where('task', $ongoing['id'])->fetch();

        $entries = $this->timeline($weekAgo, $today);
        $this->assertSame(['Started last month', 'Done this week'], array_column($entries, 'title'));
        $this->assertSame([], $entries[0]['changes']);
        $this->assertNull($entries[0]['ended_at']);
        $this->assertSame([['todo', 'in_progress'], ['in_progress', 'done']], array_map(fn (array $change) => [$change['from'], $change['to']], $entries[1]['changes']));
        $this->assertNotNull($entries[1]['ended_at']);

        $january = $this->timeline('2026-01-10', '2026-01-31');
        $this->assertSame(['Done long ago'], array_column($january, 'title'));
        $this->assertStringStartsWith('2026-01-05T09:00:00', $january[0]['started_at']);
        $this->assertStringStartsWith('2026-01-20T17:00:00', $january[0]['ended_at']);
        $this->assertSame([], $this->timeline('2026-01-21', '2026-01-31'));
    }

    public function testTimelineStartsATaskWhenItLeavesToDo()
    {
        $this->register();
        $waited = $this->task('Waited in the backlog');
        Task::update()->set('created_at', date('Y-m-d H:i:s', strtotime('-20 days')))->where('id', $waited['id'])->fetch();
        $this->move($waited, null, 'in_progress')->assertOk();

        [$entry] = $this->timeline(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
        $this->assertSame('todo', $entry['initial_category']);
        $this->assertSame('in_progress', $entry['status_category']);
        $this->assertStringStartsWith(date('Y-m-d'), $entry['started_at']);
        $this->assertStringStartsWith(date('Y-m-d', strtotime('-20 days')), $entry['created_at']);
    }

    public function testTimelineStaysInItsWorkspaceAndValidatesThePeriod()
    {
        $this->get('/api/tasks/timeline?from=2026-11-01&to=2026-11-30')->assertUnauthorized();

        $this->register();
        $task = $this->task('Mine');
        $this->move($task, null, 'in_progress')->assertOk();
        $this->logOut();

        $this->register('bob@example.com');
        $this->assertSame([], $this->timeline(date('Y-m-d', strtotime('-1 day')), date('Y-m-d')));

        $this->get('/api/tasks/timeline?from=2026-11-30&to=2026-11-01')->assertUnprocessableContent();
        $this->get('/api/tasks/timeline?from=2026-01-01&to=2026-06-01')->assertUnprocessableContent();
        $this->get('/api/tasks/timeline?from=2026-02-30&to=2026-03-01')->assertUnprocessableContent();
    }
}
