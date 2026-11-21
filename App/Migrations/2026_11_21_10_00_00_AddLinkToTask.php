<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        // A single page the task points to (a document, a mockup, a ticket elsewhere)
        $plan->addColumn('task', ModelField::string('link', 2000)->nullable());
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropColumn('task', 'link');
    }
};
