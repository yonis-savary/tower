<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        // The handler rules edited in the front, written into the downloaded configuration. Never sent to a device
        $plan->addColumn('access_key', ModelField::string('handlers'));
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropColumn('access_key', 'handlers');
    }
};
