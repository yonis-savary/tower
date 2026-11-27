<?php

namespace App\Domain\Agenda;

use App\Domain\Event\EventAnnouncer;
use App\Domain\History\HistoryService;
use App\Models\AgendaItem;
use App\Models\AgendaItemException;
use App\Models\AgendaItemParticipant;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Resources\AtomDate;
use Cube\Data\Bunch;
use Cube\Data\Database\Database;
use Cube\Data\Database\Query;

class AgendaService
{
    public const UPCOMING_TYPE = 'agenda.item.upcoming';

    // What a change made from an occurrence of a recurring item concerns
    public const SCOPE_OCCURRENCE = 'occurrence';
    public const SCOPE_FOLLOWING = 'following';
    public const SCOPE_SERIES = 'series';
    public const SCOPES = [self::SCOPE_OCCURRENCE, self::SCOPE_FOLLOWING, self::SCOPE_SERIES];

    private const WITH = ['participants._user', 'exceptions'];

    private bool $inTransaction = false;

    public function __construct(
        protected Database $database,
        protected HistoryService $history,
        protected EventAnnouncer $announcer,
    ) {}

    /**
     * The items overlapping [from, to), a recurring item giving one entry per occurrence : a copy of the item
     * with the dates of that occurrence.
     *
     * @param list<int>  $workspaces
     * @param ?list<int> $participants
     *
     * @return Bunch<int,AgendaItem>
     */
    public function listIn(array $workspaces, string $from, string $to, ?array $participants = null): Bunch
    {
        $query = AgendaItem::select(self::WITH)
            ->whereIn('workspace', $workspaces)
            ->where('starts_at', $to, '<')
            // The occurrences of a recurring item are found once it is read
            ->whereGroup(fn (Query $group) => $group
                ->where('ends_at', $from, '>')
                ->or()->where('recurrence', null, '<>'));

        // An item nobody takes part in concerns everyone, whoever is looked at
        if (null !== $participants)
            $query->whereGroup(fn (Query $group) => $group
                ->whereIn('id', $this->itemsOf($participants))
                ->or()->whereNotIn('id', $this->itemsOf()));

        $from = new \DateTimeImmutable($from);
        $to = new \DateTimeImmutable($to);
        $entries = [];
        foreach ($query->fetch() as $item) {
            if ($item->isRecurring())
                array_push($entries, ...$this->occurrencesOf($item, $from, $to));
            else
                $entries[] = $item;
        }

        usort($entries, fn (AgendaItem $a, AgendaItem $b) => [self::moment($a->starts_at), $a->id] <=> [self::moment($b->starts_at), $b->id]);

        return Bunch::wrap($entries);
    }

    /**
     * @param ?list<int> $users
     */
    private function itemsOf(?array $users = null): Query
    {
        $query = Query::select('agenda_item_participant')->selectField('item', 'agenda_item_participant');

        return null === $users ? $query : $query->whereIn('user', $users, 'agenda_item_participant');
    }

    /**
     * @return list<AgendaItem>
     */
    private function occurrencesOf(AgendaItem $item, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $first = self::moment($item->starts_at);
        $duration = $first->diff(self::moment($item->ends_at));
        $excluded = $this->excludedDays($item);

        $occurrences = [];
        foreach (Recurrence::of($item)->startsOverlapping($first, $duration, $from, $to) as $start) {
            if (!in_array($start->format('Y-m-d'), $excluded, true))
                $occurrences[] = $this->occurrence($item, $start);
        }

        return $occurrences;
    }

    /**
     * The occurrence of a recurring item starting on a day (Y-m-d, server time), unless it was removed.
     */
    public function occurrenceOf(AgendaItem $item, string $day): ?AgendaItem
    {
        if (!$item->isRecurring() || in_array($day, $this->excludedDays($item), true))
            return null;

        $start = Recurrence::of($item)->startOn(self::moment($item->starts_at), $day);

        return $start ? $this->occurrence($item, $start) : null;
    }

    private function occurrence(AgendaItem $item, \DateTimeImmutable $start): AgendaItem
    {
        $first = self::moment($item->starts_at);
        $occurrence = clone $item;
        $occurrence->starts_at = $start->format('Y-m-d H:i:s');
        $occurrence->ends_at = $start->add($first->diff(self::moment($item->ends_at)))->format('Y-m-d H:i:s');

        return $occurrence;
    }

    /**
     * @return list<string>
     */
    private function excludedDays(AgendaItem $item): array
    {
        $exceptions = $item->references['exceptions'] ?? AgendaItemException::select()->where('item', $item->id)->fetch();

        return array_map(fn (AgendaItemException $exception) => self::day($exception->occurs_on), $exceptions);
    }

    /**
     * @param list<int> $workspaces
     */
    public function find(array $workspaces, int $id): ?AgendaItem
    {
        $item = AgendaItem::findWhere(['id' => $id], self::WITH);

        return $item && in_array($item->workspace, $workspaces, true) ? $item : null;
    }

    public function create(int $workspace, int $user, AgendaItemData $data): ?AgendaItem
    {
        if (!$this->areMembers($workspace, $data->participants))
            return null;

        $item = $this->insert($workspace, $user, $data);

        $this->announce($item, 'agenda.item.created', $user);

        return $item;
    }

    /**
     * Without an occurrence, `$data` replaces the item and its dates are those of its first occurrence. With one,
     * they are the new dates of that occurrence, and `$scope` tells which occurrences change. The answer is the
     * item holding the change : a new one when an occurrence or the following ones are split from their item.
     */
    public function update(AgendaItem $item, int $user, AgendaItemData $data, ?AgendaItem $occurrence = null, string $scope = self::SCOPE_SERIES): ?AgendaItem
    {
        if (!$this->areMembers($item->workspace, $data->participants))
            return null;

        if (null === $occurrence) {
            $updated = $this->save($item, $data);
            $this->announce($updated, 'agenda.item.updated', $user);

            return $updated;
        }

        $day = self::day($occurrence->starts_at);
        if (self::SCOPE_FOLLOWING === $scope && $day === self::day($item->starts_at))
            $scope = self::SCOPE_SERIES;

        $updated = match ($scope) {
            self::SCOPE_OCCURRENCE => $this->detach($item, $user, $data, $day),
            self::SCOPE_FOLLOWING => $this->split($item, $user, $data, $day),
            default => $this->moveSeries($item, $data, $day),
        };

        $this->announceAs($updated, 'agenda.item.updated', HistoryService::ORIGIN_USER, $user, self::SCOPE_SERIES === $scope ? [] : ['series' => $item->id, 'occurrence' => $day, 'scope' => $scope]);

        return $updated;
    }

    // The occurrence becomes an item of its own, which happens once
    private function detach(AgendaItem $item, int $user, AgendaItemData $data, string $day): AgendaItem
    {
        return $this->transaction(function () use ($item, $user, $data, $day) {
            AgendaItemException::fromArray(['item' => $item->id, 'occurs_on' => $day])->save();

            return $this->insert($item->workspace, $user, $data->withRecurrence(null), $item->created_by);
        });
    }

    // The item stops the day before the occurrence, a new item starts with it
    private function split(AgendaItem $item, int $user, AgendaItemData $data, string $day): AgendaItem
    {
        return $this->transaction(function () use ($item, $user, $data, $day) {
            $item->recurrence_until = self::dayBefore($day);
            $item->updated_at = date('Y-m-d H:i:s');
            $item->save();

            $created = $this->insert($item->workspace, $user, $this->keepRule($item, $data, $day), $item->created_by);
            if ($created->isRecurring())
                $this->moveExceptions($item, $created, $day, self::daysBetween($day, self::day($data->startsAt)));

            return AgendaItem::findWhere(['id' => $created->id], self::WITH);
        });
    }

    // Every occurrence moves as the given one did : the first one by as many days, all of them to its time
    private function moveSeries(AgendaItem $item, AgendaItemData $data, string $day): AgendaItem
    {
        if (null === $data->recurrence)
            return $this->save($item, $data);

        $days = self::daysBetween($day, self::day($data->startsAt));
        $newStart = self::moment($data->startsAt);
        $first = self::moment($item->starts_at)->modify("{$days} days")
            ->setTime((int) $newStart->format('H'), (int) $newStart->format('i'), (int) $newStart->format('s'));
        $end = $first->add($newStart->diff(self::moment($data->endsAt)));

        $moved = $this->keepRule($item, $data, $day)->withDates($first->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'));

        return $this->transaction(function () use ($item, $moved, $days) {
            $this->moveExceptions($item, $item, null, $days);

            return $this->save($item, $moved);
        });
    }

    /**
     * A rule sent back as the item has it moves with the occurrence : a weekly item moved from Monday to Tuesday
     * then repeats on Tuesdays.
     */
    private function keepRule(AgendaItem $item, AgendaItemData $data, string $day): AgendaItemData
    {
        $current = Recurrence::of($item);
        if (null === $data->recurrence || null === $current)
            return $data;

        $sent = $data->recurrence->until($current->until);

        return $sent->toArray() === $current->toArray()
            ? $data->withRecurrence($current->shiftedBy(self::daysBetween($day, self::day($data->startsAt)))->until($data->recurrence->until))
            : $data;
    }

    // The removed occurrences from `$since` move with the item, by as many days
    private function moveExceptions(AgendaItem $from, AgendaItem $to, ?string $since, int $days): void
    {
        $exceptions = AgendaItemException::select()->where('item', $from->id)->fetch();
        AgendaItemException::delete()->where('item', $from->id)->fetch();

        foreach ($exceptions as $exception) {
            $day = self::day($exception->occurs_on);
            $moved = null === $since || $day >= $since;
            AgendaItemException::fromArray([
                'item' => $moved ? $to->id : $from->id,
                'occurs_on' => $moved ? (new \DateTimeImmutable($day))->modify("{$days} days")->format('Y-m-d') : $day,
            ])->save();
        }
    }

    /**
     * Without an occurrence, the whole item is deleted. With one, `$scope` tells which occurrences are.
     */
    public function delete(AgendaItem $item, int $user, ?AgendaItem $occurrence = null, string $scope = self::SCOPE_SERIES): void
    {
        $day = null === $occurrence ? null : self::day($occurrence->starts_at);
        if (self::SCOPE_FOLLOWING === $scope && $day === self::day($item->starts_at))
            $scope = self::SCOPE_SERIES;

        if (null === $occurrence || self::SCOPE_SERIES === $scope) {
            $this->transaction(function () use ($item) {
                AgendaItemParticipant::delete()->where('item', $item->id)->fetch();
                AgendaItemException::delete()->where('item', $item->id)->fetch();
                AgendaItem::deleteId($item->id);
            });

            $this->announce($item, 'agenda.item.deleted', $user);

            return;
        }

        if (self::SCOPE_OCCURRENCE === $scope)
            AgendaItemException::fromArray(['item' => $item->id, 'occurs_on' => $day])->save();
        else {
            $item->recurrence_until = self::dayBefore($day);
            $item->updated_at = date('Y-m-d H:i:s');
            $item->save();
        }

        $this->announceAs($occurrence, 'agenda.item.deleted', HistoryService::ORIGIN_USER, $user, ['occurrence' => $day, 'scope' => $scope]);
    }

    public function announceUpcoming(): void
    {
        $this->announceUpcomingAt(new \DateTimeImmutable());
    }

    public function announceUpcomingAt(\DateTimeImmutable $now): void
    {
        $minute = new \DateTimeImmutable($now->format('Y-m-d H:i:00'));
        $workspaces = Workspace::select()->where('agenda_reminder_minutes', null, '<>')->fetch();

        foreach ($workspaces as $workspace) {
            $start = $minute->modify("+{$workspace->agenda_reminder_minutes} minutes");
            $next = $start->modify('+1 minute');
            $details = ['minutes' => $workspace->agenda_reminder_minutes];

            // An all-day item has no hour to be reminded of
            $items = AgendaItem::select(self::WITH)
                ->where('workspace', $workspace->id)
                ->where('all_day', false)
                ->where('starts_at', $next->format('Y-m-d H:i:s'), '<')
                ->whereGroup(fn (Query $group) => $group
                    ->where('starts_at', $start->format('Y-m-d H:i:s'), '>=')
                    ->or()->where('recurrence', null, '<>'))
                ->order('id', 'ASC')
                ->fetch();

            foreach ($items as $item) {
                if (!$item->isRecurring()) {
                    $this->announceAs($item, self::UPCOMING_TYPE, HistoryService::ORIGIN_AGENDA, null, $details);
                    continue;
                }

                $occurrence = $this->occurrenceOf($item, $start->format('Y-m-d'));
                if ($occurrence && self::moment($occurrence->starts_at) >= $start && self::moment($occurrence->starts_at) < $next)
                    $this->announceAs($occurrence, self::UPCOMING_TYPE, HistoryService::ORIGIN_AGENDA, null, [...$details, 'occurrence' => $start->format('Y-m-d')]);
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function payloadOf(AgendaItem $item): array
    {
        return [
            'item' => $item->id,
            'title' => $item->title,
            'starts_at' => AtomDate::format($item->starts_at),
            'ends_at' => AtomDate::format($item->ends_at),
            'all_day' => (bool) $item->all_day,
            'link' => $item->link,
            'color' => $item->color,
            'recurrence' => Recurrence::of($item)?->toArray(),
            'participants' => array_map(
                fn (AgendaItemParticipant $participant) => ['id' => $participant->_user->id, 'email' => $participant->_user->email],
                $item->participants ?? [],
            ),
        ];
    }

    // The author of a series stays the author of the items split from it
    private function insert(int $workspace, int $user, AgendaItemData $data, ?int $author = null): AgendaItem
    {
        $item = new AgendaItem([
            'workspace' => $workspace,
            'created_by' => $author ?? $user,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->save($item, $data);
    }

    private function save(AgendaItem $item, AgendaItemData $data): AgendaItem
    {
        $this->transaction(function () use ($item, $data) {
            $item->title = $data->title;
            $item->description = $data->description;
            $item->starts_at = $data->startsAt;
            $item->ends_at = $data->endsAt;
            $item->all_day = $data->allDay;
            $item->link = $data->link;
            $item->color = $data->color;
            $item->recurrence = $data->recurrence?->frequency;
            $item->recurrence_interval = $data->recurrence->interval ?? 1;
            $item->recurrence_weekdays = $data->recurrence?->weekdays ? implode(',', $data->recurrence->weekdays) : null;
            $item->recurrence_until = $data->recurrence?->until;
            $item->updated_at = date('Y-m-d H:i:s');
            $item->save();

            AgendaItemParticipant::delete()->where('item', $item->id)->fetch();
            foreach ($data->participants as $user)
                AgendaItemParticipant::fromArray(['item' => $item->id, 'user' => $user])->save();

            // The removed occurrences mean nothing to an item that happens once
            if (null === $data->recurrence)
                AgendaItemException::delete()->where('item', $item->id)->fetch();
        });

        return AgendaItem::findWhere(['id' => $item->id], self::WITH);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return T
     */
    private function transaction(\Closure $callback): mixed
    {
        // The database knows a single level : an inner call joins the outer transaction
        if ($this->inTransaction)
            return $callback();

        $this->inTransaction = true;
        $result = null;
        try {
            $failure = $this->database->transaction(function () use ($callback, &$result) {
                $result = $callback();
            });
        } finally {
            $this->inTransaction = false;
        }

        if ($failure)
            throw $failure;

        return $result;
    }

    /**
     * @param list<int> $users
     */
    private function areMembers(int $workspace, array $users): bool
    {
        if (!$users)
            return true;

        $members = WorkspaceMember::select()
            ->where('workspace', $workspace)
            ->whereIn('user', $users)
            ->count();

        return count($users) === $members;
    }

    private function announce(AgendaItem $item, string $type, int $user): void
    {
        $this->announceAs($item, $type, HistoryService::ORIGIN_USER, $user);
    }

    /**
     * @param array<string,mixed> $details
     */
    private function announceAs(AgendaItem $item, string $type, string $origin, ?int $user, array $details = []): void
    {
        $line = $this->history->log($item->workspace, $type, ['title' => $item->title, 'item' => $item->id, ...$details], $origin, $user);
        $payload = [...$this->payloadOf($item), ...$details];

        $this->announcer->announce($item->workspace, $line->id, $type, json_encode((object) $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function moment(\DateTimeInterface|string $date): \DateTimeImmutable
    {
        return $date instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($date) : new \DateTimeImmutable($date);
    }

    private static function day(\DateTimeInterface|string $date): string
    {
        return self::moment($date)->format('Y-m-d');
    }

    private static function dayBefore(string $day): string
    {
        return (new \DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d');
    }

    private static function daysBetween(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
    }
}
