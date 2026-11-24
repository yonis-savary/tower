<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->create('agenda_item', [
            ModelField::id(),
            ModelField::integer('workspace')->notNull()->references('workspace', 'id'),
            ModelField::string('title', 255)->notNull(),
            ModelField::string('description')->notNull(),
            ModelField::datetime('starts_at')->notNull(),
            // Exclusive, as FullCalendar reads it : an all-day item of one day ends at midnight the next day
            ModelField::datetime('ends_at')->notNull(),
            ModelField::boolean('all_day')->notNull(),
            ModelField::string('link', 2000),
            ModelField::integer('created_by')->notNull()->references('user', 'id'),
            ModelField::datetime('created_at')->notNull(),
            ModelField::datetime('updated_at')->notNull(),
        ]);

        $plan->create('agenda_item_participant', [
            ModelField::id(),
            ModelField::integer('item')->notNull()->references('agenda_item', 'id'),
            ModelField::integer('user')->notNull()->references('user', 'id'),
        ]);
        $plan->addUniqueIndex('agenda_item_participant', ['item', 'user']);

        $plan->addColumn('workspace', ModelField::integer('agenda_reminder_minutes')->nullable());
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropColumn('workspace', 'agenda_reminder_minutes');
        $plan->dropTable('agenda_item_participant');
        $plan->dropTable('agenda_item');
    }
};
