<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        // Null for the keys created before : nobody can be told apart as their creator
        $plan->addColumn('access_key', ModelField::integer('created_by')->nullable()->references('user', 'id'));
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropColumn('access_key', 'created_by');
    }
};
