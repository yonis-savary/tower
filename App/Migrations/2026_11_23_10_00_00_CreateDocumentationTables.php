<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->create('doc_page', [
            ModelField::id(),
            ModelField::integer('workspace')->notNull()->references('workspace', 'id'),
            ModelField::integer('parent')->references('doc_page', 'id'),
            ModelField::string('title', 255)->notNull(),
            ModelField::string('content')->notNull(),
            ModelField::string('tags', 1000)->notNull(),
            ModelField::integer('version')->notNull(),
            ModelField::datetime('archived_at'),
            ModelField::integer('created_by')->notNull()->references('user', 'id'),
            ModelField::integer('updated_by')->notNull()->references('user', 'id'),
            ModelField::datetime('created_at')->notNull(),
            ModelField::datetime('updated_at')->notNull(),
        ]);

        $plan->create('doc_page_version', [
            ModelField::id(),
            ModelField::integer('page')->notNull()->references('doc_page', 'id'),
            ModelField::integer('version')->notNull(),
            ModelField::string('title', 255)->notNull(),
            ModelField::string('content')->notNull(),
            ModelField::string('tags', 1000)->notNull(),
            ModelField::integer('user')->notNull()->references('user', 'id'),
            ModelField::string('token', 100),
            ModelField::datetime('created_at')->notNull(),
        ]);
        $plan->addUniqueIndex('doc_page_version', ['page', 'version']);

        $plan->create('doc_image', [
            ModelField::id(),
            ModelField::integer('workspace')->notNull()->references('workspace', 'id'),
            ModelField::string('name', 255)->notNull(),
            ModelField::string('type', 50)->notNull(),
            ModelField::integer('size')->notNull(),
            ModelField::string('file', 100)->notNull(),
            ModelField::integer('user')->notNull()->references('user', 'id'),
            ModelField::datetime('created_at')->notNull(),
        ]);
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropTable('doc_image');
        $plan->dropTable('doc_page_version');
        $plan->dropTable('doc_page');
    }
};
