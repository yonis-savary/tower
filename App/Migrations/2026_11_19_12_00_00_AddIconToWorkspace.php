<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        // The name of the file in Storage/WorkspaceIcons : a new name for each upload, so it also versions the icon
        $plan->addColumn('workspace', ModelField::string('icon', 100)->nullable());
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropColumn('workspace', 'icon');
    }
};
