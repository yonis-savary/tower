<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->create('sprint_link', [
            ModelField::id(),
            ModelField::integer('sprint')->notNull()->references('sprint', 'id'),
            ModelField::string('label', 100),
            ModelField::string('url', 2000)->notNull(),
            ModelField::datetime('created_at')->notNull(),
        ]);
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropTable('sprint_link');
    }
};
