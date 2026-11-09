<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->create('workspace', [
            ModelField::id(),
            ModelField::string('name', 100)->notNull(),
            ModelField::string('accent_color', 7),
            ModelField::integer('author')->notNull()->references('user', 'id'),
        ]);
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropTable('workspace');
    }
};
