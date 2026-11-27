<?php

namespace App\Domain\History;

use App\Domain\Event\EventService;
use App\Domain\Workspace\WorkspaceSignals;
use App\Models\EventDelivery;
use App\Models\History;
use Cube\Data\Bunch;
use Cube\Data\Database\Database;

class HistoryService
{
    public const ORIGIN_USER = 'user';
    public const ORIGIN_DEVICE = 'device';
    public const ORIGIN_INTEGRATION = 'integration';
    public const ORIGIN_WEBHOOK = 'webhook';
    public const ORIGIN_SCHEDULE = 'schedule';
    public const ORIGIN_MCP = 'mcp';
    public const ORIGIN_AGENDA = 'agenda';

    public const DELIVERY_PENDING = 'pending';
    public const DELIVERY_RUNNING = 'running';
    public const DELIVERY_RECEIVED = 'received';
    public const DELIVERY_SUCCEEDED = 'succeeded';
    public const DELIVERY_FAILED = 'failed';
    /** What a connector may answer */
    public const DELIVERY_ANSWERS = [self::DELIVERY_RUNNING, self::DELIVERY_RECEIVED, self::DELIVERY_SUCCEEDED, self::DELIVERY_FAILED];
    private const DELIVERY_FINAL = [self::DELIVERY_RECEIVED, self::DELIVERY_SUCCEEDED, self::DELIVERY_FAILED];
    private const MAX_RESPONSE_BYTES = 65535;

    private const PAGE_SIZE = 50;

    protected WorkspaceSignals $signals;

    public function __construct(?WorkspaceSignals $signals = null)
    {
        $this->signals = $signals ?? WorkspaceSignals::getInstance();
    }

    /**
     * @param string               $type What happened, translated by the frontend (`device.connected`, `key.revoked`...)
     * @param array<string,mixed> $details Values shown with the line
     * @param array<string,scalar|null> $signal  Sent with the `history.created` signal, for the browsers drawing the flow of events
     */
    public function log(int $workspace, string $type, array $details = [], string $origin = self::ORIGIN_USER, ?int $user = null, ?int $task = null, array $signal = []): History
    {
        // A user acting through an API token is logged with the origin and the name of that token
        $actor = HistoryActor::getInstance();
        if (self::ORIGIN_USER === $origin && null !== $actor->tokenName) {
            $origin = $actor->origin;
            $details['token'] = $actor->tokenName;
        }

        // created_at as a string : generated models type a DATETIME column as DATE, which drops the time
        $line = (new History([
            'workspace' => $workspace,
            'type' => $type,
            'origin' => $origin,
            'user' => $user,
            'task' => $task,
            'details' => $this->encode($details),
            'created_at' => date('Y-m-d H:i:s'),
        ]))->save();

        $this->signals->send($workspace, WorkspaceSignals::HISTORY_CREATED, ['history_type' => $type, 'history' => $line->id, 'origin' => $origin, ...$signal]);

        return $line;
    }

    /**
     * Written before the event is sent : the connector answers with the id of this delivery.
     *
     * @param string $payload JSON object
     */
    public function logDelivery(int $history, string $connectionUuid, ?string $hostname, string $payload, ?int $accessKey = null): EventDelivery
    {
        $delivery = (new EventDelivery([
            'history' => $history,
            'connection_uuid' => $connectionUuid,
            'hostname' => $hostname,
            'payload' => $payload,
            'status' => self::DELIVERY_PENDING,
            'access_key' => $accessKey,
        ]))->save();

        $this->signalDelivery($delivery);

        return $delivery;
    }

    /**
     * Stores what a connector answered for one of its deliveries. It may come after a reconnection,
     * so the delivery is matched by the access key, not by the connection.
     *
     * @return bool false when the delivery is unknown, belongs to another key, or is already finished
     */
    public function recordResponse(int $accessKey, int $delivery, string $status, ?int $exitCode = null, ?string $output = null): bool
    {
        $found = EventDelivery::find($delivery);
        if (!$found || $found->access_key !== $accessKey || in_array($found->status, self::DELIVERY_FINAL, true))
            return false;

        $found->status = $status;
        if (in_array($status, self::DELIVERY_FINAL, true)) {
            $found->exit_code = $exitCode;
            $found->response = null === $output || '' === $output ? null : mb_scrub(mb_strcut($output, 0, self::MAX_RESPONSE_BYTES, 'UTF-8'), 'UTF-8');
            $found->responded_at = date('Y-m-d H:i:s');
        }
        $found->save();

        $this->signalDelivery($found);

        return true;
    }

    private function signalDelivery(EventDelivery $delivery): void
    {
        if ($line = History::find($delivery->history))
            $this->signals->send($line->workspace, WorkspaceSignals::DELIVERY_UPDATED, ['history' => $delivery->history, 'device' => $delivery->connection_uuid, 'status' => $delivery->status]);
    }

    public function page(int $workspace, ?int $before = null): Bunch
    {
        $query = History::select()->where('workspace', $workspace);
        if (null !== $before)
            $query->where('id', $before, '<');

        return $query
            ->order('id', 'DESC')
            ->limit(self::PAGE_SIZE)
            ->toBunch();
    }

    /**
     * @return Bunch<int,History> The events sent to the devices of a workspace : launched by hand, by a
     *                            quick action, a webhook or a schedule, or brought by an integration. Newest first, with their deliveries
     */
    public function recentEvents(int $workspace, int $limit): Bunch
    {
        return History::select(['eventDeliveries'])
            ->where('workspace', $workspace)
            ->whereGroup(fn ($query) => $query
                ->where('type', EventService::HISTORY_TYPE)
                ->or()->where('type', 'pr.%', 'LIKE')
                ->or()->where('type', 'jira.%', 'LIKE'))
            ->order('id', 'DESC')
            ->limit($limit)
            ->toBunch();
    }

    public function find(int $workspace, int $id): ?History
    {
        $line = History::find($id);

        return $line && $line->workspace === $workspace ? $line : null;
    }

    public function deliveriesOf(History $line): Bunch
    {
        return EventDelivery::select()
            ->where('history', $line->id)
            ->order('id', 'ASC')
            ->toBunch();
    }

    public function purgeExpired(HistoryConfiguration $configuration): void
    {
        $limit = date('Y-m-d H:i:s', strtotime("-{$configuration->retentionDays} days"));
        $database = Database::getInstance();

        $database->exec('DELETE FROM event_delivery WHERE history IN (SELECT id FROM history WHERE created_at < {})', [$limit]);
        $database->exec('DELETE FROM history WHERE created_at < {}', [$limit]);
        $database->exec('DELETE FROM webhook_call WHERE created_at < {}', [$limit]);
    }

    private function encode(array $details): string
    {
        // As an object : an empty array must stay {}
        return json_encode((object) $details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
