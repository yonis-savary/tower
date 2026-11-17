<?php

namespace App\EventTypes\Sources;

use App\EventTypes\EventTypeSource;
use App\Models\Webhook;
use App\Services\WebhookService;

class WebhookTypes implements EventTypeSource
{
    public function __construct(
        protected WebhookService $webhooks,
    ) {}

    public static function name(): string
    {
        return 'webhook';
    }

    public function typesOf(int $workspace): array
    {
        $types = [];
        foreach (Webhook::select()->where('workspace', $workspace)->fetch() as $webhook)
            array_push($types, ...$this->webhooks->eventsOf($webhook));

        return $types;
    }
}
