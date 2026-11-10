<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->create('webhook', [
            ModelField::id(),
            ModelField::integer('workspace')->notNull()->references('workspace', 'id'),
            ModelField::string('name', 100)->notNull(),
            ModelField::string('uuid', 36)->notNull()->unique(),
            // Encrypted with APP_KEY : a signature (HMAC) can only be checked with the secret itself.
            // Null for an unsecured webhook (see AddSecuredToWebhook)
            ModelField::string('secret', 500),
            // Types of the events triggered by each call, as a JSON list
            ModelField::string('events', 2500)->notNull(),
            ModelField::boolean('enabled')->notNull(),
            ModelField::datetime('created_at')->notNull(),
            ModelField::datetime('last_called_at'),
        ]);

        $plan->create('webhook_call', [
            ModelField::id(),
            ModelField::integer('webhook')->notNull()->references('webhook', 'id'),
            ModelField::string('status', 20)->notNull(),
            ModelField::string('ip', 45),
            ModelField::string('user_agent', 255),
            // Only for an authenticated call : an unknown caller cannot fill the database
            ModelField::string('payload'),
            ModelField::datetime('created_at')->notNull(),
        ]);
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropTable('webhook_call');
        $plan->dropTable('webhook');
    }
};
