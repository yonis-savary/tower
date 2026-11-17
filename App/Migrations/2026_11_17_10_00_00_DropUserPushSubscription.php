<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

// A browser now receives the notifications of the workspaces it was enabled in only. The existing
// subscriptions cannot be given a workspace : they are dropped, and each browser is enabled again.
// The dry run of a migration cannot see a table dropped and created again : the next one creates it
return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->dropTable('push_subscription');
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->create('push_subscription', [
            ModelField::id(),
            ModelField::integer('user')->notNull()->references('user', 'id'),
            ModelField::string('endpoint', 500)->notNull()->unique(),
            ModelField::string('p256dh', 200)->notNull(),
            ModelField::string('auth', 100)->notNull(),
            ModelField::string('content_encoding', 20)->notNull(),
            ModelField::string('user_agent', 255),
            ModelField::datetime('created_at')->notNull(),
            ModelField::datetime('last_success_at'),
        ]);
    }
};
