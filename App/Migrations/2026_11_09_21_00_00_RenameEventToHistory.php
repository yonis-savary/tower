<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->renameField('event', 'payload', 'details');
        $plan->addColumn('event', ModelField::integer('user')->references('user', 'id'));
        $plan->renameTable('event', 'history');
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropConstraint('history', 'history_ibfk_2');
        $plan->dropColumn('history', 'user');
        $plan->renameField('history', 'details', 'payload');
        $plan->renameTable('history', 'event');
    }
};
