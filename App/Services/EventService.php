<?php

namespace App\Services;

use App\Channels\DevicesChannel;
use App\Models\History;
use App\Models\QuickAction;
use Cube\Data\Bunch;

class EventService
{
    public const HISTORY_TYPE = 'event.triggered';

    public function __construct(
        protected DevicesChannel $devices,
        protected HistoryService $history,
    ) {}

    /**
     * Writes an event launched by a user in the history and sends it to the connected devices of the workspace.
     */
    public function triggerAsUser(int $workspace, int $user, string $type, string $payload): History
    {
        $line = $this->history->log($workspace, self::HISTORY_TYPE, ['event' => $type, 'payload' => json_decode($payload)], user: $user);

        $this->devices->sendEvent($workspace, $line->id, $type, $payload);

        return $line;
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

    public function createQuickAction(int $workspace, string $name, string $type, string $payload): QuickAction
    {
        // created_at as a string : generated models type a DATETIME column as DATE, which drops the time
        return (new QuickAction([
            'workspace' => $workspace,
            'name' => $name,
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
