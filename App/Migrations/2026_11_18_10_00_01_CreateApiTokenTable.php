<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->create('api_token', [
            ModelField::id(),
            ModelField::integer('user')->notNull()->references('user', 'id'),
            ModelField::integer('workspace')->notNull()->references('workspace', 'id'),
            ModelField::string('name', 100)->notNull(),
            // Only the SHA-256 of the token is kept : it is shown once, at creation
            ModelField::string('token_hash', 64)->notNull()->unique(),
            ModelField::string('scope', 10)->notNull(),
            ModelField::datetime('created_at')->notNull(),
            ModelField::datetime('last_used_at'),
            ModelField::datetime('expires_at'),
        ]);
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropTable('api_token');
    }
};
