<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        // One browser where a user enabled the notifications of one workspace
        $plan->create('push_subscription', [
            ModelField::id(),
            ModelField::integer('workspace')->notNull()->references('workspace', 'id'),
            ModelField::integer('user')->notNull()->references('user', 'id'),
            // Given by the push service of the browser : the same in every workspace it is enabled in
            ModelField::string('endpoint', 500)->notNull(),
            ModelField::string('p256dh', 200)->notNull(),
            ModelField::string('auth', 100)->notNull(),
            ModelField::string('content_encoding', 20)->notNull(),
            ModelField::string('user_agent', 255),
            ModelField::datetime('created_at')->notNull(),
            ModelField::datetime('last_success_at'),
        ]);
        $plan->addUniqueIndex('push_subscription', ['endpoint', 'workspace']);
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropTable('push_subscription');
    }
};
