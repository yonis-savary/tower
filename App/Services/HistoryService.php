<?php

namespace App\Services;

use App\Models\EventDelivery;
use App\Models\History;
use Cube\Data\Bunch;
use Cube\Data\Database\Database;

class HistoryService
{
    public const ORIGIN_USER = 'user';
    public const ORIGIN_DEVICE = 'device';

    private const PAGE_SIZE = 50;

    /**
     * @param string               $type What happened, translated by the frontend (`device.connected`, `key.revoked`...)
     * @param array<string,mixed> $details Values shown with the line
     */
    public function log(int $workspace, string $type, array $details = [], string $origin = self::ORIGIN_USER, ?int $user = null): History
    {
        // created_at as a string : generated models type a DATETIME column as DATE, which drops the time
        return (new History([
            'workspace' => $workspace,
            'type' => $type,
            'origin' => $origin,
            'user' => $user,
            'details' => $this->encode($details),
            'created_at' => date('Y-m-d H:i:s'),
        ]))->save();
    }

    /**
     * @param string $payload JSON object
     */
    public function logDelivery(int $history, string $connectionUuid, ?string $hostname, string $payload): EventDelivery
    {
        return (new EventDelivery([
            'history' => $history,
            'connection_uuid' => $connectionUuid,
            'hostname' => $hostname,
            'payload' => $payload,
        ]))->save();
    }

    public function page(int $workspace, ?int $before = null): Bunch
    {
        $query = History::select()->where('workspace', $workspace);
        if (null !== $before)
            $query->where('id', $before, '<');

        return Bunch::wrap($query->order('id', 'DESC')->limit(self::PAGE_SIZE)->fetch());
    }

    public function find(int $workspace, int $id): ?History
    {
        $line = History::find($id);

        return $line && $line->workspace === $workspace ? $line : null;
    }

    public function deliveriesOf(History $line): Bunch
    {
        return Bunch::wrap(EventDelivery::select()->where('history', $line->id)->order('id', 'ASC')->fetch());
    }

    public function purgeExpired(HistoryConfiguration $configuration): void
    {
        $limit = date('Y-m-d H:i:s', strtotime("-{$configuration->retentionDays} days"));
        $database = Database::getInstance();

        $database->exec('DELETE FROM event_delivery WHERE history IN (SELECT id FROM history WHERE created_at < {})', [$limit]);
        $database->exec('DELETE FROM history WHERE created_at < {}', [$limit]);
    }

    private function encode(array $details): string
    {
        // As an object : an empty array must stay {}
        return json_encode((object) $details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
