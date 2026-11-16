<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        // One browser where a user enabled the notifications
        $plan->create('push_subscription', [
            ModelField::id(),
            ModelField::integer('user')->notNull()->references('user', 'id'),
            // Given by the push service of the browser, unique to this browser
            ModelField::string('endpoint', 500)->notNull()->unique(),
            ModelField::string('p256dh', 200)->notNull(),
            ModelField::string('auth', 100)->notNull(),
            ModelField::string('content_encoding', 20)->notNull(),
            ModelField::string('user_agent', 255),
            ModelField::datetime('created_at')->notNull(),
            ModelField::datetime('last_success_at'),
        ]);

        // What a member of a workspace wants to be notified of
        $plan->create('notification_rule', [
            ModelField::id(),
            ModelField::integer('workspace')->notNull()->references('workspace', 'id'),
            ModelField::integer('user')->notNull()->references('user', 'id'),
            ModelField::string('name', 100)->notNull(),
            // Event patterns, as a JSON list
            ModelField::string('listen', 2500)->notNull(),
            // {"mode": "raw"|"key", "value": "..."} as JSON, null for the default text
            ModelField::string('title', 500),
            ModelField::string('body', 500),
            ModelField::boolean('enabled')->notNull(),
            ModelField::datetime('created_at')->notNull(),
        ]);
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropTable('notification_rule');
        $plan->dropTable('push_subscription');
    }
};
