<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->create('user', [
            ModelField::id(),
            ModelField::string('email', 255)->notNull()->unique(),
            ModelField::string('password_hash', 255)->notNull(),
            ModelField::datetime('created_at')->notNull(),
            ModelField::datetime('disabled_at'),
        ]);
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropTable('user');
    }
};
