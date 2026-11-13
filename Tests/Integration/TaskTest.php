<?php

namespace Tests\Integration;

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
}
