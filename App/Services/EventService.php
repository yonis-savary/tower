<?php

namespace App\Services;

use App\EventTypes\EventTypeSource;
use App\Models\History;
use App\Models\QuickAction;
use Cube\Data\Bunch;

class EventService
{
    public const HISTORY_TYPE = 'event.triggered';

    public function __construct(
        protected EventAnnouncer $announcer,
        protected HistoryService $history,
    ) {}

    /**
     * Writes an event launched by a user in the history and announces it to the devices and the browsers of the workspace.
     */
    public function triggerAsUser(int $workspace, int $user, string $type, string $payload): History
    {
        $line = $this->history->log($workspace, self::HISTORY_TYPE, ['event' => $type, 'payload' => json_decode($payload)], user: $user);

        $this->announcer->announce($workspace, $line->id, $type, $payload);

        return $line;
    }

    /**
     * Same as an event launched by a user, but brought by a call to a webhook of the workspace.
     */
    public function triggerFromWebhook(int $workspace, string $webhook, string $type, string $payload): History
    {
        $line = $this->history->log($workspace, self::HISTORY_TYPE, ['event' => $type, 'payload' => json_decode($payload), 'webhook' => $webhook], HistoryService::ORIGIN_WEBHOOK);

        $this->announcer->announce($workspace, $line->id, $type, $payload);

        return $line;
    }

    public function triggerFromSchedule(int $workspace, string $schedule, string $type, string $payload): History
    {
        $line = $this->history->log($workspace, self::HISTORY_TYPE, ['event' => $type, 'payload' => json_decode($payload), 'schedule' => $schedule], HistoryService::ORIGIN_SCHEDULE);

        $this->announcer->announce($workspace, $line->id, $type, $payload);

        return $line;
    }

    /**
     * The event types already used in a workspace, by source, to suggest while typing one.
     *
     * @return list<array{source: string, types: list<string>}> Each type once per source, sorted
     */
    public function suggestedTypes(int $workspace): array
    {
        $suggestions = [];
        foreach (Bunch::fromImplements(EventTypeSource::class) as $source) {
            $types = array_values(array_unique($source->typesOf($workspace)));
            if (!$types)
                continue;

            sort($types);
            $suggestions[] = ['source' => $source::name(), 'types' => $types];
        }

        return $suggestions;
    }

    /**
     * @return Bunch<int,QuickAction> Oldest first
     */
    public function listQuickActions(int $workspace): Bunch
    {
        return Bunch::wrap(
            QuickAction::select()
                ->where('workspace', $workspace)
                ->order('id', 'ASC')
                ->fetch()
        );
    }

    public function createQuickAction(int $workspace, string $name, ?string $description, string $type, string $payload): QuickAction
    {
        // created_at as a string : generated models type a DATETIME column as DATE, which drops the time
        return (new QuickAction([
            'workspace' => $workspace,
            'name' => $name,
            'description' => '' === trim((string) $description) ? null : trim($description),
            'type' => $type,
            'payload' => $payload,
            'created_at' => date('Y-m-d H:i:s'),
        ]))->save();
    }

    public function deleteQuickAction(QuickAction $action): void
    {
        QuickAction::deleteId($action->id);
    }

    public function findQuickAction(int $workspace, int $id): ?QuickAction
    {
        $action = QuickAction::find($id);

        return $action && $action->workspace === $workspace ? $action : null;
    }
}
