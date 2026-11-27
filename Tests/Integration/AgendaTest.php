<?php

namespace Tests\Integration;

use App\Domain\Agenda\AgendaService;
use App\Domain\Workspace\WorkspaceSignals;
use App\Models\AgendaItem;
use App\Models\AgendaItemException;
use App\Models\AgendaItemParticipant;
use App\Models\History;
use App\Models\ScheduledEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Session\SelectedWorkspace;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;
use Cube\Web\Websocket\Broadcast\Broadcast;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;

class AgendaTest extends CubeTestCase
{
    private const PASSWORD = 'correct horse battery';

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

    private function register(string $email): int
    {
        return $this->postJson('/api/auth/register', ['email' => $email, 'password' => self::PASSWORD])->assertCreated()->json()['id'];
    }

    private function logIn(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => self::PASSWORD])->assertOk();
    }

    private function addWorkspace(int $userId, string $name): int
    {
        $workspace = Workspace::fromArray(['name' => $name, 'author' => $userId])->save();
        WorkspaceMember::fromArray(['workspace' => $workspace->id, 'user' => $userId])->save();

        return $workspace->id;
    }

    private function item(array $values = []): array
    {
        return ['title' => 'Standup', 'starts_at' => '2026-12-01 09:00:00', 'ends_at' => '2026-12-01 09:15:00', ...$values];
    }

    private function range(?array $workspaces = null): string
    {
        $query = 'from=2026-11-30%2000:00:00&to=2026-12-07%2000:00:00';

        return null === $workspaces ? $query : $query.'&workspaces='.implode(',', $workspaces);
    }

    public function testRoutesRequireAuthentication()
    {
        $this->get('/api/agenda/items?'.$this->range())->assertUnauthorized();
        $this->postJson('/api/agenda/items', $this->item())->assertUnauthorized();
        $this->get('/api/agenda/schedules?'.$this->range())->assertUnauthorized();
    }

    public function testItemLifecycle()
    {
        $alice = $this->register('alice@example.com');

        $created = $this->postJson('/api/agenda/items', $this->item(['description' => 'Daily', 'link' => 'https://meet.example.com/standup', 'participants' => [$alice]]))->assertCreated()->json();
        $this->assertSame(SelectedWorkspace::get(), $created['workspace']);
        $this->assertSame(['alice@example.com'], array_column($created['participants'], 'email'));
        $this->assertStringStartsWith('2026-12-01T09:00:00', $created['starts_at']);
        $this->assertNotNull(History::findWhere(['type' => 'agenda.item.created']));

        $moved = $this->putJson('/api/agenda/items/'.$created['id'], $this->item(['starts_at' => '2026-12-02 10:00:00', 'ends_at' => '2026-12-02 11:00:00']))->assertOk()->json();
        $this->assertStringStartsWith('2026-12-02T10:00:00', $moved['starts_at']);
        $this->assertSame([], $moved['participants']);
        $this->assertNotNull(History::findWhere(['type' => 'agenda.item.updated']));

        $this->assertSame(['Standup'], array_column($this->get('/api/agenda/items?'.$this->range())->assertOk()->json(), 'title'));

        $this->deleteJson('/api/agenda/items/'.$created['id'])->assertNoContent();
        $this->assertSame([], $this->get('/api/agenda/items?'.$this->range())->assertOk()->json());
        $this->assertNotNull(History::findWhere(['type' => 'agenda.item.deleted']));
    }

    public function testAnItemMayHaveItsOwnColor()
    {
        $this->register('alice@example.com');

        $item = $this->postJson('/api/agenda/items', $this->item(['color' => '#22C55E']))->assertCreated()->json();
        $this->assertSame('#22c55e', $item['color']);
        $this->assertNull($this->putJson('/api/agenda/items/'.$item['id'], $this->item(['color' => null]))->assertOk()->json()['color']);
        $this->assertNull($this->postJson('/api/agenda/items', $this->item())->assertCreated()->json()['color']);
        $this->postJson('/api/agenda/items', $this->item(['color' => 'green']))->assertUnprocessableContent();
    }

    public function testTheListOnlyGivesTheItemsOverlappingTheRange()
    {
        $this->register('alice@example.com');
        $this->postJson('/api/agenda/items', $this->item(['title' => 'Before', 'starts_at' => '2026-11-29 09:00:00', 'ends_at' => '2026-11-30 00:00:00']))->assertCreated();
        $this->postJson('/api/agenda/items', $this->item(['title' => 'Across', 'starts_at' => '2026-11-29 23:00:00', 'ends_at' => '2026-11-30 01:00:00']))->assertCreated();
        $this->postJson('/api/agenda/items', $this->item(['title' => 'Holiday', 'starts_at' => '2026-12-03 00:00:00', 'ends_at' => '2026-12-04 00:00:00', 'all_day' => true]))->assertCreated();
        $this->postJson('/api/agenda/items', $this->item(['title' => 'After', 'starts_at' => '2026-12-07 00:00:00', 'ends_at' => '2026-12-07 01:00:00']))->assertCreated();

        $this->assertSame(['Across', 'Holiday'], array_column($this->get('/api/agenda/items?'.$this->range())->assertOk()->json(), 'title'));
    }

    public function testTheListKeepsTheItemsOfTheGivenParticipants()
    {
        $bob = $this->register('bob@example.com');
        $this->logOut();
        $alice = $this->register('alice@example.com');
        $this->postJson('/api/workspaces/'.SelectedWorkspace::get().'/members', ['email' => 'bob@example.com'])->assertOk();

        $this->postJson('/api/agenda/items', $this->item(['title' => 'Alice', 'participants' => [$alice]]))->assertCreated();
        $this->postJson('/api/agenda/items', $this->item(['title' => 'Bob', 'participants' => [$bob]]))->assertCreated();
        $this->postJson('/api/agenda/items', $this->item(['title' => 'Both', 'participants' => [$alice, $bob]]))->assertCreated();
        $this->postJson('/api/agenda/items', $this->item(['title' => 'Nobody']))->assertCreated();

        $titles = fn (string $participants) => array_column($this->get('/api/agenda/items?'.$this->range().'&participants='.$participants)->assertOk()->json(), 'title');
        $this->assertSame(['Alice', 'Both', 'Nobody'], $titles((string) $alice));
        $this->assertSame(['Bob', 'Both', 'Nobody'], $titles((string) $bob));
        $this->assertSame(['Alice', 'Bob', 'Both', 'Nobody'], $titles("{$alice},{$bob}"));
        $this->assertCount(4, $this->get('/api/agenda/items?'.$this->range())->assertOk()->json());
        $this->get('/api/agenda/items?'.$this->range().'&participants=bob')->assertUnprocessableContent();
    }

    public function testAnInstantKeepsItsMomentWhateverTheTimezoneOfTheBrowser()
    {
        $this->register('alice@example.com');

        $item = $this->postJson('/api/agenda/items', $this->item(['starts_at' => '2026-12-01T10:00:00+01:00', 'ends_at' => '2026-12-01T09:30:00Z']))->assertCreated()->json();
        $this->assertEquals(new \DateTimeImmutable('2026-12-01T09:00:00Z'), new \DateTimeImmutable($item['starts_at']));
        $this->assertEquals(new \DateTimeImmutable('2026-12-01T09:30:00Z'), new \DateTimeImmutable($item['ends_at']));

        $moved = $this->putJson('/api/agenda/items/'.$item['id'], $this->item(['starts_at' => $item['starts_at'], 'ends_at' => $item['ends_at']]))->assertOk()->json();
        $this->assertSame($item['starts_at'], $moved['starts_at']);

        $listed = $this->get('/api/agenda/items?from=2026-12-01T09:15:00%2B00:00&to=2026-12-01T10:00:00%2B00:00')->assertOk()->json();
        $this->assertSame([$item['id']], array_column($listed, 'id'));

        $this->postJson('/api/agenda/items', $this->item(['starts_at' => '2026-02-30T10:00:00+01:00']))->assertUnprocessableContent();
        $this->postJson('/api/agenda/items', $this->item(['starts_at' => '2026-12-01T10:00:00+01:00', 'ends_at' => '2026-12-01T09:00:00Z']))->assertUnprocessableContent();
    }

    public function testInvalidItemsAndRangesAreRefused()
    {
        $this->register('alice@example.com');

        foreach ([
            ['title' => ''],
            ['ends_at' => '2026-12-01 09:00:00'],
            ['starts_at' => 'tomorrow'],
            ['link' => 'javascript:alert(1)'],
        ] as $values) {
            $this->postJson('/api/agenda/items', $this->item($values))->assertUnprocessableContent();
        }

        $this->get('/api/agenda/items?from=2026-12-07%2000:00:00&to=2026-11-30%2000:00:00')->assertUnprocessableContent();
        $this->get('/api/agenda/items?from=2026-01-01%2000:00:00&to=2026-06-01%2000:00:00')->assertUnprocessableContent();
        $this->get('/api/agenda/items?'.$this->range().'&workspaces=one')->assertUnprocessableContent();
        $this->assertSame(0, AgendaItem::select()->count());
    }

    public function testParticipantsAreMembersOfTheWorkspaceOfTheItem()
    {
        $bob = $this->register('bob@example.com');
        $this->logOut();
        $this->register('alice@example.com');

        $this->postJson('/api/agenda/items', $this->item(['participants' => [$bob]]))->assertUnprocessableContent();

        $this->postJson('/api/workspaces/'.SelectedWorkspace::get().'/members', ['email' => 'bob@example.com'])->assertOk();
        $item = $this->postJson('/api/agenda/items', $this->item(['participants' => [$bob]]))->assertCreated()->json();

        $this->delete('/api/workspaces/'.SelectedWorkspace::get().'/members/'.$bob)->assertNoContent();
        $this->assertSame(0, AgendaItemParticipant::select()->where('item', $item['id'])->count());
    }

    public function testEveryWorkspaceOfTheUserIsReadAndWrittenWithoutSwitching()
    {
        $alice = $this->register('alice@example.com');
        $current = SelectedWorkspace::get();
        $perso = $this->addWorkspace($alice, 'Perso');

        $this->postJson('/api/agenda/items', $this->item(['title' => 'Pro']))->assertCreated();
        $personal = $this->postJson('/api/agenda/items', $this->item(['title' => 'Dentist', 'workspace' => $perso]))->assertCreated()->json();
        $this->assertSame($perso, $personal['workspace']);
        $this->assertSame($current, SelectedWorkspace::get());

        $this->assertSame(['Pro'], array_column($this->get('/api/agenda/items?'.$this->range())->assertOk()->json(), 'title'));
        $both = $this->get('/api/agenda/items?'.$this->range([$current, $perso]))->assertOk()->json();
        $this->assertEqualsCanonicalizing(['Pro', 'Dentist'], array_column($both, 'title'));

        $this->putJson('/api/agenda/items/'.$personal['id'], $this->item(['title' => 'Dentist, moved']))->assertOk();
        $this->assertSame($perso, AgendaItem::find($personal['id'])->workspace);
        $this->assertSame('agenda.item.updated', History::select()->where('workspace', $perso)->order('id', 'DESC')->first()->type);
    }

    public function testTheWorkspacesOfSomeoneElseAreOutOfReach()
    {
        $bob = $this->register('bob@example.com');
        $bobs = SelectedWorkspace::get();
        $item = $this->postJson('/api/agenda/items', $this->item())->assertCreated()->json();
        $this->logOut();
        $this->register('alice@example.com');

        $this->get('/api/agenda/items?'.$this->range([$bobs]))->assertNotFound();
        $this->get('/api/agenda/schedules?'.$this->range([$bobs]))->assertNotFound();
        $this->postJson('/api/agenda/items', $this->item(['workspace' => $bobs]))->assertNotFound();
        $this->putJson('/api/agenda/items/'.$item['id'], $this->item(['title' => 'Mine now']))->assertNotFound();
        $this->deleteJson('/api/agenda/items/'.$item['id'])->assertNotFound();
        $this->assertSame('Standup', AgendaItem::find($item['id'])->title);
    }

    public function testUpcomingIsAnnouncedTheSetMinutesBeforeAnItem()
    {
        $alice = $this->register('alice@example.com');
        $workspace = SelectedWorkspace::get();
        $this->putJson("/api/workspaces/{$workspace}", ['name' => 'Pro', 'accent_color' => null, 'agenda_reminder_minutes' => 10])->assertOk();
        $this->postJson('/api/agenda/items', $this->item(['participants' => [$alice]]))->assertCreated();
        $this->postJson('/api/agenda/items', $this->item(['title' => 'Holiday', 'starts_at' => '2026-12-01 09:10:00', 'ends_at' => '2026-12-02 00:00:00', 'all_day' => true]))->assertCreated();

        inject(AgendaService::class)->announceUpcomingAt(new \DateTimeImmutable('2026-12-01 08:49:00'));
        $this->assertNull(History::findWhere(['type' => AgendaService::UPCOMING_TYPE]));

        inject(AgendaService::class)->announceUpcomingAt(new \DateTimeImmutable('2026-12-01 08:50:30'));
        $lines = History::select()->where('type', AgendaService::UPCOMING_TYPE)->fetch();
        $this->assertCount(1, $lines);
        $this->assertSame('agenda', $lines[0]->origin);

        $sent = array_values(array_filter($this->broadcast->emitted, fn (array $data) => AgendaService::UPCOMING_TYPE === ($data['event'] ?? null)));
        $this->assertCount(1, $sent);
        $payload = json_decode($sent[0]['payload'], true);
        $this->assertSame('Standup', $payload['title']);
        $this->assertSame(['alice@example.com'], array_column($payload['participants'], 'email'));
        $this->assertSame(10, $payload['minutes']);

        $this->putJson("/api/workspaces/{$workspace}", ['name' => 'Pro', 'accent_color' => null, 'agenda_reminder_minutes' => null])->assertOk();
        inject(AgendaService::class)->announceUpcomingAt(new \DateTimeImmutable('2026-12-01 08:50:00'));
        $this->assertSame(1, History::select()->where('type', AgendaService::UPCOMING_TYPE)->count());
    }

    public function testTheReminderSettingIsKeptWhenNotGiven()
    {
        $this->register('alice@example.com');
        $workspace = SelectedWorkspace::get();

        $this->putJson("/api/workspaces/{$workspace}", ['name' => 'Pro', 'accent_color' => null, 'agenda_reminder_minutes' => 0])->assertUnprocessableContent();
        $this->assertSame(15, $this->putJson("/api/workspaces/{$workspace}", ['name' => 'Pro', 'accent_color' => null, 'agenda_reminder_minutes' => 15])->assertOk()->json()['agenda_reminder_minutes']);
        $this->assertSame(15, $this->putJson("/api/workspaces/{$workspace}", ['name' => 'Renamed', 'accent_color' => null])->assertOk()->json()['agenda_reminder_minutes']);
    }

    public function testScheduleOccurrencesAreGivenForTheRange()
    {
        $this->register('alice@example.com');
        $this->postJson('/api/scheduled-events', ['name' => 'Standup', 'type' => 'standup.remind', 'payload' => '{}', 'cron' => '30 9 * * 1-5'])->assertCreated();
        $this->postJson('/api/scheduled-events', ['name' => 'Poll', 'type' => 'ci.poll', 'payload' => '{}', 'cron' => '*/15 8-17 * * *'])->assertCreated();
        $this->postJson('/api/scheduled-events', ['name' => 'Once', 'type' => 'deploy.start', 'payload' => '{}', 'run_at' => '2099-12-01 18:00:00'])->assertCreated();
        $off = $this->postJson('/api/scheduled-events', ['name' => 'Off', 'type' => 'off.event', 'payload' => '{}', 'cron' => '0 12 * * *'])->assertCreated()->json();
        $this->putJson('/api/scheduled-events/'.$off['id'], ['name' => 'Off', 'type' => 'off.event', 'payload' => '{}', 'cron' => '0 12 * * *', 'enabled' => false])->assertOk();

        // From monday 2026-11-30 to wednesday 2026-12-02 excluded
        $occurrences = $this->get('/api/agenda/schedules?from=2026-11-30%2000:00:00&to=2026-12-02%2000:00:00')->assertOk()->json();

        $standups = array_values(array_filter($occurrences, fn (array $occurrence) => 'Standup' === $occurrence['name']));
        $this->assertSame(['2026-11-30T09:30', '2026-12-01T09:30'], array_map(fn (array $occurrence) => substr($occurrence['at'], 0, 16), $standups));
        $this->assertFalse($standups[0]['frequent']);

        $polls = array_values(array_filter($occurrences, fn (array $occurrence) => 'Poll' === $occurrence['name']));
        $this->assertCount(2, $polls);
        $this->assertTrue($polls[0]['frequent']);
        $this->assertSame(40, $polls[0]['runs']);
        $this->assertSame('2026-11-30T08:00', substr($polls[0]['at'], 0, 16));

        $this->assertNotContains('Off', array_column($occurrences, 'name'));
        $this->assertNotContains('Once', array_column($occurrences, 'name'));

        $once = $this->get('/api/agenda/schedules?from=2099-11-30%2000:00:00&to=2099-12-02%2000:00:00')->assertOk()->json();
        $this->assertContains('Once', array_column($once, 'name'));
    }

    public function testDeletingAWorkspaceDeletesItsAgenda()
    {
        $alice = $this->register('alice@example.com');
        $perso = $this->addWorkspace($alice, 'Perso');
        $this->postJson('/api/agenda/items', $this->item(['workspace' => $perso, 'participants' => [$alice]]))->assertCreated();

        $this->deleteJson("/api/workspaces/{$perso}", ['name' => 'Perso'])->assertNoContent();
        $this->assertSame(0, AgendaItem::select()->count());
        $this->assertSame(0, AgendaItemParticipant::select()->count());
        $this->assertNull(User::find($alice)->disabled_at);
    }

    private function weekly(array $values = []): array
    {
        // Monday 2026-11-30, on Mondays and Wednesdays
        return $this->item(['starts_at' => '2026-11-30 09:00:00', 'ends_at' => '2026-11-30 09:15:00', 'recurrence' => ['frequency' => 'weekly', 'weekdays' => [1, 3]], ...$values]);
    }

    /**
     * @return list<string> "title day hh:mm" of each listed entry
     */
    private function listed(string $from = '2026-11-30', string $to = '2026-12-14'): array
    {
        $items = $this->get("/api/agenda/items?from={$from}%2000:00:00&to={$to}%2000:00:00")->assertOk()->json();

        return array_map(fn (array $item) => $item['title'].' '.substr($item['starts_at'], 0, 10).' '.substr($item['starts_at'], 11, 5), $items);
    }

    public function testARecurringItemIsListedOncePerOccurrence()
    {
        $this->register('alice@example.com');
        $created = $this->postJson('/api/agenda/items', $this->weekly())->assertCreated()->json();
        $this->assertSame(['frequency' => 'weekly', 'interval' => 1, 'weekdays' => [1, 3], 'until' => null], $created['recurrence']);
        $this->postJson('/api/agenda/items', $this->item(['title' => 'Retro', 'starts_at' => '2026-12-01 14:00:00', 'ends_at' => '2026-12-01 15:00:00', 'recurrence' => ['frequency' => 'daily', 'interval' => 2, 'until' => '2026-12-05']]))->assertCreated();

        $this->assertSame([
            'Standup 2026-11-30 09:00', 'Retro 2026-12-01 14:00', 'Standup 2026-12-02 09:00', 'Retro 2026-12-03 14:00',
            'Retro 2026-12-05 14:00', 'Standup 2026-12-07 09:00', 'Standup 2026-12-09 09:00',
        ], $this->listed());

        $later = $this->get('/api/agenda/items?from=2027-06-01%2000:00:00&to=2027-06-08%2000:00:00')->assertOk()->json();
        $this->assertSame(['2027-06-02', '2027-06-07'], array_column($later, 'occurrence'));
        $this->assertSame($created['id'], $later[0]['id']);
        $this->assertStringEndsWith(substr($later[0]['starts_at'], 19), $later[0]['ends_at']);

        // A weekly item without days repeats on the day it starts
        $tuesday = $this->postJson('/api/agenda/items', $this->item(['recurrence' => ['frequency' => 'weekly']]))->assertCreated()->json();
        $this->assertSame([2], $tuesday['recurrence']['weekdays']);
    }

    public function testAnInvalidRecurrenceIsRefused()
    {
        $this->register('alice@example.com');

        $this->postJson('/api/agenda/items', $this->item(['recurrence' => ['frequency' => 'hourly']]))->assertUnprocessableContent();
        $this->postJson('/api/agenda/items', $this->item(['recurrence' => ['frequency' => 'daily', 'interval' => 0]]))->assertUnprocessableContent();
        $this->postJson('/api/agenda/items', $this->item(['recurrence' => ['frequency' => 'weekly', 'weekdays' => [8]]]))->assertUnprocessableContent();
        $this->postJson('/api/agenda/items', $this->item(['recurrence' => ['frequency' => 'daily', 'until' => '2026-11-30']]))->assertUnprocessableContent();
        $this->assertSame(0, AgendaItem::select()->count());
    }

    public function testAnOccurrenceIsChangedAlone()
    {
        $this->register('alice@example.com');
        $series = $this->postJson('/api/agenda/items', $this->weekly())->assertCreated()->json();

        $this->putJson("/api/agenda/items/{$series['id']}", $this->weekly(['occurrence' => '2026-12-01', 'starts_at' => '2026-12-01 10:00:00', 'ends_at' => '2026-12-01 10:15:00']))->assertNotFound();

        $detached = $this->putJson("/api/agenda/items/{$series['id']}", $this->weekly(['title' => 'Late standup', 'occurrence' => '2026-12-02', 'starts_at' => '2026-12-02 11:00:00', 'ends_at' => '2026-12-02 11:15:00']))->assertOk()->json();
        $this->assertNotSame($series['id'], $detached['id']);
        $this->assertNull($detached['recurrence']);
        $this->assertNull($detached['occurrence']);

        $this->assertSame(['Standup 2026-11-30 09:00', 'Late standup 2026-12-02 11:00', 'Standup 2026-12-07 09:00', 'Standup 2026-12-09 09:00'], $this->listed());
        $this->assertSame('2026-12-02', AgendaItemException::findWhere(['item' => $series['id']])->occurs_on->format('Y-m-d'));

        // Changed already : that occurrence no longer exists
        $this->putJson("/api/agenda/items/{$series['id']}", $this->weekly(['occurrence' => '2026-12-02', 'starts_at' => '2026-12-02 11:00:00', 'ends_at' => '2026-12-02 11:15:00']))->assertNotFound();

        $line = array_values(array_filter($this->broadcast->emitted, fn (array $data) => 'agenda.item.updated' === ($data['event'] ?? null)))[0];
        $payload = json_decode($line['payload'], true);
        $this->assertSame([$series['id'], '2026-12-02', 'occurrence'], [$payload['series'], $payload['occurrence'], $payload['scope']]);
    }

    public function testTheFollowingOccurrencesAreSplitIntoANewItem()
    {
        $this->register('alice@example.com');
        $series = $this->postJson('/api/agenda/items', $this->weekly(['recurrence' => ['frequency' => 'weekly', 'weekdays' => [1, 3], 'until' => '2026-12-31']]))->assertCreated()->json();
        $this->deleteJson("/api/agenda/items/{$series['id']}?occurrence=2026-12-09")->assertNoContent();

        $following = $this->putJson("/api/agenda/items/{$series['id']}", $this->weekly([
            'occurrence' => '2026-12-07', 'scope' => 'following',
            'starts_at' => '2026-12-07 09:30:00', 'ends_at' => '2026-12-07 09:45:00',
            'recurrence' => ['frequency' => 'weekly', 'weekdays' => [1, 3], 'until' => '2026-12-31'],
        ]))->assertOk()->json();

        $this->assertNotSame($series['id'], $following['id']);
        $this->assertSame('2026-12-31', $following['recurrence']['until']);
        $this->assertSame('2026-12-06', AgendaItem::find($series['id'])->recurrence_until->format('Y-m-d'));
        // The occurrence removed before the split stays removed
        $this->assertSame(['Standup 2026-11-30 09:00', 'Standup 2026-12-02 09:00', 'Standup 2026-12-07 09:30', 'Standup 2026-12-14 09:30', 'Standup 2026-12-16 09:30'], $this->listed('2026-11-30', '2026-12-17'));
    }

    public function testTheWholeSeriesMovesAsAnOccurrenceDoes()
    {
        $this->register('alice@example.com');
        $series = $this->postJson('/api/agenda/items', $this->weekly())->assertCreated()->json();
        $this->deleteJson("/api/agenda/items/{$series['id']}?occurrence=2026-12-07")->assertNoContent();

        // Wednesday 9:00 to Thursday 14:00, the rule sent back as it was
        $moved = $this->putJson("/api/agenda/items/{$series['id']}", $this->weekly([
            'occurrence' => '2026-12-02', 'scope' => 'series',
            'starts_at' => '2026-12-03 14:00:00', 'ends_at' => '2026-12-03 15:00:00',
        ]))->assertOk()->json();

        $this->assertSame($series['id'], $moved['id']);
        $this->assertSame([2, 4], $moved['recurrence']['weekdays']);
        $this->assertSame(['Standup 2026-12-01 14:00', 'Standup 2026-12-03 14:00', 'Standup 2026-12-10 14:00'], $this->listed());

        // The rule changed : it is taken as sent
        $this->putJson("/api/agenda/items/{$series['id']}", $this->weekly([
            'occurrence' => '2026-12-03', 'scope' => 'series',
            'starts_at' => '2026-12-03 14:00:00', 'ends_at' => '2026-12-03 15:00:00',
            'recurrence' => ['frequency' => 'weekly', 'weekdays' => [5]],
        ]))->assertOk();
        $this->assertSame(['Standup 2026-12-04 14:00', 'Standup 2026-12-11 14:00'], $this->listed());

        // Without a rule, the item happens once, on the dates sent
        $this->putJson("/api/agenda/items/{$series['id']}", $this->item(['occurrence' => '2026-12-11', 'scope' => 'series', 'starts_at' => '2026-12-11 14:00:00', 'ends_at' => '2026-12-11 15:00:00']))->assertOk();
        $this->assertSame(['Standup 2026-12-11 14:00'], $this->listed());
        $this->assertSame(0, AgendaItemException::select()->count());
    }

    public function testOccurrencesAreDeletedAloneTheFollowingOnesOrAll()
    {
        $this->register('alice@example.com');
        $series = $this->postJson('/api/agenda/items', $this->weekly())->assertCreated()->json();

        $this->deleteJson("/api/agenda/items/{$series['id']}?occurrence=2026-12-01")->assertNotFound();
        $this->deleteJson("/api/agenda/items/{$series['id']}?occurrence=2026-12-02")->assertNoContent();
        $this->assertSame(['Standup 2026-11-30 09:00', 'Standup 2026-12-07 09:00', 'Standup 2026-12-09 09:00'], $this->listed());

        $this->deleteJson("/api/agenda/items/{$series['id']}?occurrence=2026-12-09&scope=following")->assertNoContent();
        $this->assertSame(['Standup 2026-11-30 09:00', 'Standup 2026-12-07 09:00'], $this->listed());
        $this->assertSame('2026-12-08', AgendaItem::find($series['id'])->recurrence_until->format('Y-m-d'));

        $deleted = array_values(array_filter($this->broadcast->emitted, fn (array $data) => 'agenda.item.deleted' === ($data['event'] ?? null)));
        $this->assertSame(['2026-12-02', 'occurrence'], [json_decode($deleted[0]['payload'], true)['occurrence'], json_decode($deleted[0]['payload'], true)['scope']]);
        $this->assertStringStartsWith('2026-12-02T09:00', json_decode($deleted[0]['payload'], true)['starts_at']);

        $this->deleteJson("/api/agenda/items/{$series['id']}?occurrence=2026-12-07&scope=series")->assertNoContent();
        $this->assertSame([], $this->listed());
        $this->assertSame(0, AgendaItemException::select()->count());
    }

    public function testUpcomingIsAnnouncedBeforeEachOccurrence()
    {
        $this->register('alice@example.com');
        $workspace = SelectedWorkspace::get();
        $this->putJson("/api/workspaces/{$workspace}", ['name' => 'Pro', 'accent_color' => null, 'agenda_reminder_minutes' => 10])->assertOk();
        $series = $this->postJson('/api/agenda/items', $this->weekly())->assertCreated()->json();
        $this->deleteJson("/api/agenda/items/{$series['id']}?occurrence=2026-12-07")->assertNoContent();

        $agenda = inject(AgendaService::class);
        $agenda->announceUpcomingAt(new \DateTimeImmutable('2026-12-01 08:50:00'));
        $agenda->announceUpcomingAt(new \DateTimeImmutable('2026-12-07 08:50:00'));
        $this->assertSame(0, History::select()->where('type', AgendaService::UPCOMING_TYPE)->count());

        $agenda->announceUpcomingAt(new \DateTimeImmutable('2026-12-09 08:50:00'));
        $sent = array_values(array_filter($this->broadcast->emitted, fn (array $data) => AgendaService::UPCOMING_TYPE === ($data['event'] ?? null)));
        $this->assertCount(1, $sent);
        $payload = json_decode($sent[0]['payload'], true);
        $this->assertSame('2026-12-09', $payload['occurrence']);
        $this->assertStringStartsWith('2026-12-09T09:00', $payload['starts_at']);
    }
}
