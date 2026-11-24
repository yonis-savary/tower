<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        // daily, weekly, monthly, yearly ; null for a single item
        $plan->addColumn('agenda_item', ModelField::string('recurrence', 10)->nullable());
        $plan->addColumn('agenda_item', ModelField::integer('recurrence_interval')->notNull()->default(1));
        // ISO-8601 days of the week of a weekly item, separated by commas (1 = Monday)
        $plan->addColumn('agenda_item', ModelField::string('recurrence_weekdays', 20)->nullable());
        // Last day an occurrence may start on, included
        $plan->addColumn('agenda_item', ModelField::date('recurrence_until')->nullable());

        // The days whose occurrence was removed from a recurring item, or detached into an item of its own
        $plan->create('agenda_item_exception', [
            ModelField::id(),
            ModelField::integer('item')->notNull()->references('agenda_item', 'id'),
            ModelField::date('occurs_on')->notNull(),
        ]);
        $plan->addUniqueIndex('agenda_item_exception', ['item', 'occurs_on']);
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropTable('agenda_item_exception');
        $plan->dropColumn('agenda_item', 'recurrence_until');
        $plan->dropColumn('agenda_item', 'recurrence_weekdays');
        $plan->dropColumn('agenda_item', 'recurrence_interval');
        $plan->dropColumn('agenda_item', 'recurrence');
    }
};
