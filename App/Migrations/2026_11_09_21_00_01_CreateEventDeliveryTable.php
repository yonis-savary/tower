<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->create('event_delivery', [
            ModelField::id(),
            ModelField::integer('history')->notNull()->references('history', 'id'),
            ModelField::string('connection_uuid', 36)->notNull(),
            ModelField::string('hostname', 150),
            ModelField::string('payload')->notNull(),
            ModelField::string('response'),
            ModelField::datetime('responded_at'),
        ]);
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropTable('event_delivery');
    }
};
