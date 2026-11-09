<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->create('integration', [
            ModelField::id(),
            ModelField::integer('workspace')->notNull()->references('workspace', 'id'),
            ModelField::string('type', 30)->notNull(),
            ModelField::string('settings')->notNull(),
            ModelField::string('credentials')->notNull(),
            ModelField::datetime('last_synced_at'),
            ModelField::string('last_error'),
            ModelField::datetime('created_at')->notNull(),
        ]);
        $plan->addUniqueIndex('integration', ['workspace', 'type']);

        $plan->create('pull_request', [
            ModelField::id(),
            ModelField::integer('workspace')->notNull()->references('workspace', 'id'),
            ModelField::integer('integration')->notNull()->references('integration', 'id'),
            ModelField::string('external_id', 64)->notNull(),
            ModelField::string('repository', 150)->notNull(),
            ModelField::string('source_branch', 255)->notNull(),
            ModelField::string('target_branch', 255)->notNull(),
            ModelField::string('source_commit', 64)->notNull(),
            ModelField::string('url', 500)->notNull(),
            ModelField::string('title', 500)->notNull(),
            ModelField::string('author', 255)->notNull(),
            ModelField::string('state', 20)->notNull(),
            ModelField::boolean('draft')->notNull(),
            ModelField::integer('comment_count')->notNull(),
            ModelField::string('pipeline_status', 20)->notNull(),
            ModelField::datetime('synced_at')->notNull(),
        ]);
        $plan->addUniqueIndex('pull_request', ['integration', 'repository', 'external_id']);

        $plan->create('pull_request_approvals', [
            ModelField::id(),
            ModelField::integer('pull_request')->notNull()->references('pull_request', 'id'),
            ModelField::string('external_user_id', 64)->notNull(),
            ModelField::string('display_name', 255)->notNull(),
            ModelField::string('status', 20)->notNull(),
        ]);
        $plan->addUniqueIndex('pull_request_approvals', ['pull_request', 'external_user_id']);
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropTable('pull_request_approvals');
        $plan->dropTable('pull_request');
        $plan->dropTable('integration');
    }
};
