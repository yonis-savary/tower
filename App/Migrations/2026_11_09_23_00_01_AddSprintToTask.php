<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->addColumn('task', ModelField::integer('sprint')->references('sprint', 'id'));
        $plan->addColumn('task', ModelField::string('description')->notNull());
        $plan->addColumn('task', ModelField::string('status', 20)->notNull());
        $plan->addColumn('task', ModelField::integer('position')->notNull());
        $plan->addColumn('history', ModelField::integer('task')->references('task', 'id'));
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropConstraint('history', 'history_ibfk_3');
        $plan->dropColumn('history', 'task');
        $plan->dropColumn('task', 'position');
        $plan->dropColumn('task', 'status');
        $plan->dropColumn('task', 'description');
        $plan->dropConstraint('task', 'task_ibfk_2');
        $plan->dropColumn('task', 'sprint');
    }
};
