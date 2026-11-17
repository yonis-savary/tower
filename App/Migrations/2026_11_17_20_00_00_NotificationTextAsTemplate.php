<?php

use App\Models\NotificationRule;
use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;

// The title and the body of a rule were {"mode": "raw"|"key", "value": ...} as JSON : they become templates
return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        foreach (NotificationRule::select()->fetch() as $rule) {
            $rule->title = $this->toTemplate($rule->title);
            $rule->body = $this->toTemplate($rule->body);
            $rule->save();
        }
    }

    // A key of the payload cannot be told from a raw text once written : both go back as raw text
    public function down(Plan $plan, Database $database)
    {
        foreach (NotificationRule::select()->fetch() as $rule) {
            $rule->title = null === $rule->title ? null : json_encode(['mode' => 'raw', 'value' => $rule->title], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $rule->body = null === $rule->body ? null : json_encode(['mode' => 'raw', 'value' => $rule->body], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $rule->save();
        }
    }

    private function toTemplate(?string $stored): ?string
    {
        $text = null === $stored ? null : json_decode($stored, true);
        if (!is_string($text['value'] ?? null))
            return null;

        return 'key' === ($text['mode'] ?? null) ? "{payload.{$text['value']}}" : $text['value'];
    }
};
