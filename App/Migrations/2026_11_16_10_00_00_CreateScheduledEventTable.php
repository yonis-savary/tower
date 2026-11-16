<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->create('scheduled_event', [
            ModelField::id(),
            ModelField::integer('workspace')->notNull()->references('workspace', 'id'),
            ModelField::string('name', 100)->notNull(),
            ModelField::string('type', 100)->notNull(),
            ModelField::string('payload')->notNull(),
            ModelField::string('cron', 100)->notNull(),
            // A cron has no year : a one-time schedule keeps its full date, and is disabled once it is reached
            ModelField::datetime('run_at'),
            ModelField::boolean('enabled')->notNull(),
            ModelField::datetime('last_run_at'),
            ModelField::datetime('created_at')->notNull(),
        ]);
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropTable('scheduled_event');
    }
};
