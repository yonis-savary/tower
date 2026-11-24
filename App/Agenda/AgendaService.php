<?php

namespace App\Agenda;

use App\Models\AgendaItem;
use App\Models\AgendaItemParticipant;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Resources\AtomDate;
use App\Services\EventAnnouncer;
use App\Services\HistoryService;
use Cube\Data\Bunch;
use Cube\Data\Database\Database;
use Cube\Data\Database\Query;

class AgendaService
{
    public const UPCOMING_TYPE = 'agenda.item.upcoming';

    public function __construct(
        protected Database $database,
        protected HistoryService $history,
        protected EventAnnouncer $announcer,
    ) {}

    /**
     * @param list<int>  $workspaces
     * @param ?list<int> $participants
     *
     * @return Bunch<int,AgendaItem>
     */
    public function listIn(array $workspaces, string $from, string $to, ?array $participants = null): Bunch
    {
        $query = AgendaItem::select(['participants._user'])
            ->whereIn('workspace', $workspaces)
            ->where('starts_at', $to, '<')
            ->where('ends_at', $from, '>');

        // An item nobody takes part in concerns everyone, whoever is looked at
        if (null !== $participants)
            $query->whereGroup(fn (Query $group) => $group
                ->whereIn('id', $this->itemsOf($participants))
                ->or()->whereNotIn('id', $this->itemsOf()));

        return Bunch::wrap($query->order('starts_at', 'ASC')->order('id', 'ASC')->fetch());
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
     * @param list<int> $workspaces
     */
    public function find(array $workspaces, int $id): ?AgendaItem
    {
        $item = AgendaItem::findWhere(['id' => $id], ['participants._user']);

        return $item && in_array($item->workspace, $workspaces, true) ? $item : null;
    }

    public function create(int $workspace, int $user, AgendaItemData $data): ?AgendaItem
    {
        if (!$this->areMembers($workspace, $data->participants))
            return null;

        $now = date('Y-m-d H:i:s');
        $item = new AgendaItem([
            'workspace' => $workspace,
            'created_by' => $user,
            'created_at' => $now,
        ]);
        $item = $this->save($item, $data);

        $this->announce($item, 'agenda.item.created', $user);

        return $item;
    }

    public function update(AgendaItem $item, int $user, AgendaItemData $data): ?AgendaItem
    {
        if (!$this->areMembers($item->workspace, $data->participants))
            return null;

        $item = $this->save($item, $data);

        $this->announce($item, 'agenda.item.updated', $user);

        return $item;
    }

    public function delete(AgendaItem $item, int $user): void
    {
        $failure = $this->database->transaction(function () use ($item) {
            AgendaItemParticipant::delete()->where('item', $item->id)->fetch();
            AgendaItem::deleteId($item->id);
        });

        if ($failure)
            throw $failure;

        $this->announce($item, 'agenda.item.deleted', $user);
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

            // An all-day item has no hour to be reminded of
            $items = AgendaItem::select(['participants._user'])
                ->where('workspace', $workspace->id)
                ->where('all_day', false)
                ->where('starts_at', $start->format('Y-m-d H:i:s'), '>=')
                ->where('starts_at', $start->modify('+1 minute')->format('Y-m-d H:i:s'), '<')
                ->order('id', 'ASC')
                ->fetch();

            foreach ($items as $item)
                $this->announceAs($item, self::UPCOMING_TYPE, HistoryService::ORIGIN_AGENDA, null, ['minutes' => $workspace->agenda_reminder_minutes]);
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
            'participants' => array_map(
                fn (AgendaItemParticipant $participant) => ['id' => $participant->_user->id, 'email' => $participant->_user->email],
                $item->participants ?? [],
            ),
        ];
    }

    private function save(AgendaItem $item, AgendaItemData $data): AgendaItem
    {
        $failure = $this->database->transaction(function () use ($item, $data) {
            $item->title = $data->title;
            $item->description = $data->description;
            $item->starts_at = $data->startsAt;
            $item->ends_at = $data->endsAt;
            $item->all_day = $data->allDay;
            $item->link = $data->link;
            $item->color = $data->color;
            $item->updated_at = date('Y-m-d H:i:s');
            $item->save();

            AgendaItemParticipant::delete()->where('item', $item->id)->fetch();
            foreach ($data->participants as $user)
                AgendaItemParticipant::fromArray(['item' => $item->id, 'user' => $user])->save();
        });

        if ($failure)
            throw $failure;

        return AgendaItem::findWhere(['id' => $item->id], ['participants._user']);
    }

    /**
     * @param list<int> $users
     */
    private function areMembers(int $workspace, array $users): bool
    {
        if (!$users)
            return true;

        return WorkspaceMember::select()->where('workspace', $workspace)->whereIn('user', $users)->count() === count($users);
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
}
