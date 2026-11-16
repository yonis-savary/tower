<?php

namespace Tests\Integration;

use App\Models\History;
use App\Models\ScheduledEvent;
use App\Services\ScheduledEventService;
use App\Services\WorkspaceSignals;
use App\Session\SelectedWorkspace;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;
use Cube\Web\Websocket\Broadcast\Broadcast;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;

class ScheduledEventTest extends CubeTestCase
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
                if (!in_array($data['type'] ?? null, WorkspaceSignals::SIGNALS, true))
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

    private function register(string $email): void
    {
        $this->postJson('/api/auth/register', ['email' => $email, 'password' => 'correct horse battery'])->assertCreated();
    }

    private function createSchedule(array $timing = ['cron' => '0 9 * * 1-5']): array
    {
        return $this->postJson('/api/scheduled-events', ['name' => 'Standup', 'type' => 'standup.remind', 'payload' => '{"room":"blue"}', ...$timing])->assertCreated()->json();
    }

    private function triggerDueAt(string $moment): void
    {
        inject(ScheduledEventService::class)->triggerDueAt(new \DateTimeImmutable($moment));
    }

    public function testRoutesRequireAuthentication()
    {
        $this->get('/api/scheduled-events')->assertUnauthorized();
        $this->postJson('/api/scheduled-events', ['name' => 'a', 'type' => 'a.b', 'payload' => '{}', 'cron' => '* * * * *'])->assertUnauthorized();
    }

    public function testRecurringScheduleLifecycle()
    {
        $this->register('alice@example.com');
        $schedule = $this->createSchedule();

        $this->assertSame('0 9 * * 1-5', $schedule['cron']);
        $this->assertNull($schedule['run_at']);
        $this->assertTrue($schedule['enabled']);
        $this->assertNotNull(History::findWhere(['type' => 'schedule.created']));

        $updated = $this->putJson('/api/scheduled-events/'.$schedule['id'], ['name' => 'Retro', 'type' => 'retro.remind', 'payload' => '{}', 'cron' => '*/15 * * * *', 'enabled' => false])->assertOk()->json();
        $this->assertSame(['Retro', 'retro.remind', '*/15 * * * *', false], [$updated['name'], $updated['type'], $updated['cron'], $updated['enabled']]);
        $this->assertNotNull(History::findWhere(['type' => 'schedule.disabled']));

        $this->assertSame(['Retro'], array_column($this->get('/api/scheduled-events')->assertOk()->json(), 'name'));

        $this->deleteJson('/api/scheduled-events/'.$schedule['id'])->assertNoContent();
        $this->assertSame([], $this->get('/api/scheduled-events')->assertOk()->json());
        $this->assertNotNull(History::findWhere(['type' => 'schedule.deleted']));
    }

    public function testOneTimeScheduleGetsItsCronFromItsDate()
    {
        $this->register('alice@example.com');
        $schedule = $this->createSchedule(['run_at' => '2099-06-05 08:07:00']);

        $this->assertSame('7 8 5 6 *', $schedule['cron']);
        $this->assertStringStartsWith('2099-06-05T08:07:00', $schedule['run_at']);
    }

    public function testInvalidSchedulesAreRefused()
    {
        $this->register('alice@example.com');

        foreach ([
            [],
            ['cron' => '* * * * *', 'run_at' => '2099-01-01 10:00:00'],
            ['cron' => 'every day'],
            ['cron' => '61 * * * *'],
            ['cron' => '*/0 * * * *'],
            ['run_at' => '2000-01-01 10:00:00'],
            ['run_at' => 'tomorrow'],
        ] as $timing) {
            $this->postJson('/api/scheduled-events', ['name' => 'Bad', 'type' => 'a.b', 'payload' => '{}', ...$timing])->assertUnprocessableContent();
        }

        $this->postJson('/api/scheduled-events', ['name' => 'Bad', 'type' => 'Not Valid', 'payload' => '{}', 'cron' => '* * * * *'])->assertUnprocessableContent();
        $this->assertSame([], $this->get('/api/scheduled-events')->assertOk()->json());
    }

    public function testRecurringScheduleTriggersOnMatchingMinutes()
    {
        $this->register('alice@example.com');
        $schedule = $this->createSchedule();

        // 2099-06-05 is a Friday, 2099-06-06 a Saturday
        $this->triggerDueAt('2099-06-06 09:00:00');
        $this->triggerDueAt('2099-06-05 09:01:00');
        $this->assertSame([], $this->broadcast->emitted);

        $this->triggerDueAt('2099-06-05 09:00:42');
        $this->assertSame('standup.remind', $this->broadcast->emitted[0]['event']);
        $this->assertSame('{"room":"blue"}', $this->broadcast->emitted[0]['payload']);

        $line = History::findWhere(['type' => 'event.triggered']);
        $this->assertSame('schedule', $line->origin);
        $this->assertSame('{"event":"standup.remind","payload":{"room":"blue"},"schedule":"Standup"}', $line->details);

        $listed = $this->get('/api/scheduled-events')->assertOk()->json()[0];
        $this->assertStringStartsWith('2099-06-05T09:00:00', $listed['last_run_at']);
        $this->assertTrue($listed['enabled']);

        $this->putJson('/api/scheduled-events/'.$schedule['id'], ['name' => 'Standup', 'type' => 'standup.remind', 'payload' => '{}', 'cron' => '0 9 * * 1-5', 'enabled' => false])->assertOk();
        $this->triggerDueAt('2099-06-08 09:00:00');
        $this->assertCount(1, $this->broadcast->emitted);
    }

    public function testWeeklyScheduleTriggersOnEachListedDay()
    {
        $this->register('alice@example.com');
        $this->createSchedule(['cron' => '30 8 * * 1,2,4']);

        // 2099-06-08 is a Monday
        foreach (['2099-06-08', '2099-06-09', '2099-06-10', '2099-06-11', '2099-06-12'] as $day)
            $this->triggerDueAt("{$day} 08:30:00");

        $this->assertCount(3, $this->broadcast->emitted);
    }

    public function testOneTimeScheduleTriggersOnceThenStops()
    {
        $this->register('alice@example.com');
        $this->createSchedule(['run_at' => '2099-06-05 08:07:00']);

        // Same day and time, another year : the cron matches, the date does not
        $this->triggerDueAt('2098-06-05 08:07:00');
        $this->assertSame([], $this->broadcast->emitted);

        $this->triggerDueAt('2099-06-05 08:07:00');
        $this->assertCount(1, $this->broadcast->emitted);
        $this->assertFalse($this->get('/api/scheduled-events')->assertOk()->json()[0]['enabled']);

        $this->triggerDueAt('2100-06-05 08:07:00');
        $this->assertCount(1, $this->broadcast->emitted);
    }

    public function testMissedOneTimeScheduleIsDroppedWithoutTriggering()
    {
        $this->register('alice@example.com');
        $this->createSchedule(['run_at' => '2099-06-05 08:07:00']);

        $this->triggerDueAt('2099-06-05 08:08:00');

        $this->assertSame([], $this->broadcast->emitted);
        $this->assertFalse((bool) ScheduledEvent::findWhere(['name' => 'Standup'])->enabled);
    }

    public function testManualTriggerKeepsTheSchedule()
    {
        $this->register('alice@example.com');
        $schedule = $this->createSchedule(['run_at' => '2099-06-05 08:07:00']);

        $this->postJson('/api/scheduled-events/'.$schedule['id'].'/trigger', [])->assertNoContent();

        $this->assertSame('standup.remind', $this->broadcast->emitted[0]['event']);
        $this->assertSame('user', History::findWhere(['type' => 'event.triggered'])->origin);
        $listed = $this->get('/api/scheduled-events')->assertOk()->json()[0];
        $this->assertTrue($listed['enabled']);
        $this->assertNull($listed['last_run_at']);
    }

    public function testSchedulesStayInTheirWorkspace()
    {
        $this->register('alice@example.com');
        $schedule = $this->createSchedule();

        $this->logOut();
        $this->register('bob@example.com');

        $this->assertSame([], $this->get('/api/scheduled-events')->assertOk()->json());
        $this->putJson('/api/scheduled-events/'.$schedule['id'], ['name' => 'Mine', 'type' => 'a.b', 'payload' => '{}', 'cron' => '* * * * *', 'enabled' => true])->assertNotFound();
        $this->postJson('/api/scheduled-events/'.$schedule['id'].'/trigger', [])->assertNotFound();
        $this->deleteJson('/api/scheduled-events/'.$schedule['id'])->assertNotFound();
        $this->assertSame([], $this->broadcast->emitted);
    }
}
