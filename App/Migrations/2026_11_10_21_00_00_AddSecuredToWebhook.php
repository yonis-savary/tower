<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        // Chosen at creation, never changed : an unsecured webhook has no secret, its address is enough
        $plan->addColumn('webhook', ModelField::boolean('secured')->notNull()->default(true));
        // For a database created before the secret became nullable in CreateWebhookTables (SQLite ignores it)
        $plan->alterColumn('webhook', 'secret', ModelField::string('secret', 500));
    }

    public function down(Plan $plan, Database $database)
    {
        $database->exec('DELETE FROM webhook_call WHERE webhook IN (SELECT id FROM webhook WHERE secured = FALSE)');
        $database->exec('DELETE FROM webhook WHERE secured = FALSE');
        $plan->alterColumn('webhook', 'secret', ModelField::string('secret', 500)->notNull());
        $plan->dropColumn('webhook', 'secured');
    }
};
