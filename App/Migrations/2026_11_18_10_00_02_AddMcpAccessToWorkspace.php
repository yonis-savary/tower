<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->addColumn('workspace', ModelField::string('mcp_access', 10)->notNull()->default('read'));
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropColumn('workspace', 'mcp_access');
    }
};
