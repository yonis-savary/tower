<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        // A single-use link to download a configuration from a terminal, without a session
        $plan->create('config_download', [
            ModelField::id(),
            ModelField::integer('access_key')->notNull()->references('access_key', 'id'),
            ModelField::string('token_hash', 64)->notNull()->unique(),
            ModelField::string('format', 10)->notNull(),
            // The configuration with its secret, encrypted with APP_KEY
            ModelField::string('payload')->notNull(),
            ModelField::datetime('expires_at')->notNull(),
            ModelField::datetime('used_at'),
            ModelField::datetime('created_at')->notNull(),
        ]);
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropTable('config_download');
    }
};
