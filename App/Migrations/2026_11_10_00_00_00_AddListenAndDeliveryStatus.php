<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        // Patterns of the events this configuration receives, as a JSON list : everything by default
        $plan->addColumn('access_key', ModelField::string('listen', 1000)->notNull()->default('["*"]'));

        // Deliveries written before the connector answered have no known status
        $plan->addColumn('event_delivery', ModelField::string('status', 20)->notNull()->default('unknown'));
        $plan->addColumn('event_delivery', ModelField::integer('exit_code'));
        $plan->addColumn('event_delivery', ModelField::integer('access_key')->references('access_key', 'id'));
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropConstraint('event_delivery', 'event_delivery_ibfk_2');
        $plan->dropColumn('event_delivery', 'access_key');
        $plan->dropColumn('event_delivery', 'exit_code');
        $plan->dropColumn('event_delivery', 'status');
        $plan->dropColumn('access_key', 'listen');
    }
};
