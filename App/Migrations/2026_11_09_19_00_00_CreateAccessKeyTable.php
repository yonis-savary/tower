<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->create('access_key', [
            ModelField::id(),
            ModelField::integer('workspace')->notNull()->references('workspace', 'id'),
            ModelField::string('name', 100)->notNull(),
            ModelField::string('client_id', 64)->notNull()->unique(),
            ModelField::string('secret_hash', 64)->notNull(),
            ModelField::datetime('created_at')->notNull(),
            ModelField::datetime('last_used_at'),
            ModelField::datetime('revoked_at'),
        ]);
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropTable('access_key');
    }
};
