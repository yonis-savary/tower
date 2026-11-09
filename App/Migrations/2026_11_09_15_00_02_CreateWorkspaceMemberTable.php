<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->create('workspace_member', [
            ModelField::id(),
            ModelField::integer('workspace')->notNull()->references('workspace', 'id'),
            ModelField::integer('user')->notNull()->references('user', 'id'),
        ]);

        $plan->addUniqueIndex('workspace_member', ['workspace', 'user']);
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropTable('workspace_member');
    }
};
