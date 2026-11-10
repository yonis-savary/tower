<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->addColumn('workspace', ModelField::string('sections', 255));
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropColumn('workspace', 'sections');
    }
};
