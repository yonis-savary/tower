<?php

namespace App\Domain\Event;

use App\Domain\AccessKey\Connector\ConnectorConfiguration;
use App\Domain\Event\Types\EventTypeSource;
use App\Domain\History\HistoryService;
use App\Models\EventDelivery;
use App\Models\History;
use App\Models\QuickAction;
use Cube\Data\Bunch;
use Cube\Env\Logger\Logger;

class EventService
{
    public const HISTORY_TYPE = 'event.triggered';
    public const MAX_NEXT_EVENTS = 10;

    public function __construct(
        protected EventAnnouncer $announcer,
        protected HistoryService $history,
        protected ConnectorConfiguration $connector,
    ) {}

    /**
     * Writes an event launched by a user in the history and announces it to the devices and the browsers of the workspace.
     */
    public function triggerAsUser(int $workspace, int $user, string $type, string $payload): History
    {
        $line = $this->history->log($workspace, self::HISTORY_TYPE, ['event' => $type, 'payload' => json_decode($payload)], user: $user, signal: ['event' => $type]);

        $this->announcer->announce($workspace, $line->id, $type, $payload);

        return $line;
    }

    /**
     * Same as an event launched by a user, but brought by a call to a webhook of the workspace.
     */
    public function triggerFromWebhook(int $workspace, int $webhook, string $webhookName, string $type, string $payload): History
    {
        $line = $this->history->log($workspace, self::HISTORY_TYPE, ['event' => $type, 'payload' => json_decode($payload), 'webhook' => $webhookName], HistoryService::ORIGIN_WEBHOOK, signal: ['event' => $type, 'source' => $webhook]);

        $this->announcer->announce($workspace, $line->id, $type, $payload);

        return $line;
    }

    public function triggerFromSchedule(int $workspace, int $schedule, string $scheduleName, string $type, string $payload): History
    {
        $line = $this->history->log($workspace, self::HISTORY_TYPE, ['event' => $type, 'payload' => json_decode($payload), 'schedule' => $scheduleName], HistoryService::ORIGIN_SCHEDULE, signal: ['event' => $type, 'source' => $schedule]);

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
     * The events a connector described in its final answer, as `next` : it only sends those of the
     * handlers that succeeded. A chain never goes on to the type of the event it answers ; longer
     * loops are the business of whoever writes the handlers.
     *
     * @param list<array{type: string, payload?: array<string,mixed>}> $next
     */
    public function triggerNext(int $delivery, array $next): void
    {
        if (!$this->connector->allowNextEvents)
            return;

        $found = EventDelivery::find($delivery);
        if (!$found || !in_array($found->status, [HistoryService::DELIVERY_SUCCEEDED, HistoryService::DELIVERY_FAILED], true) || !$answered = History::find($found->history))
            return;

        $answeredType = $this->eventTypeOf($answered);
        foreach ($next as $event) {
            if ($event['type'] === $answeredType) {
                Logger::getInstance()->warning('A connector answered {type} with the same event as next, ignored', ['type' => $answeredType, 'delivery' => $delivery]);
                continue;
            }

            $this->triggerFromDevice($answered, $found, $event['type'], json_encode((object) ($event['payload'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    private function triggerFromDevice(History $answered, EventDelivery $delivery, string $type, string $payload): History
    {
        $details = ['event' => $type, 'payload' => json_decode($payload), 'device' => $delivery->hostname, 'answering' => $answered->id];
        $line = $this->history->log($answered->workspace, self::HISTORY_TYPE, $details, HistoryService::ORIGIN_DEVICE, signal: ['event' => $type, 'device' => $delivery->connection_uuid]);

        $this->announcer->announce($answered->workspace, $line->id, $type, $payload);

        return $line;
    }

    /**
     * The type the devices received for a line : the event itself for an `event.triggered` line.
     */
    private function eventTypeOf(History $line): string
    {
        if (self::HISTORY_TYPE !== $line->type)
            return $line->type;

        return json_decode((string) $line->details, true)['event'] ?? $line->type;
    }

    /**
     * @return Bunch<int,QuickAction> Oldest first
     */
    public function listQuickActions(int $workspace): Bunch
    {
        return QuickAction::select()
            ->where('workspace', $workspace)
            ->order('id', 'ASC')
            ->toBunch();
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
