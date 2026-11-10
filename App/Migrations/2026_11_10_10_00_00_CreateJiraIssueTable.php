<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->create('jira_issue', [
            ModelField::id(),
            ModelField::integer('workspace')->notNull()->references('workspace', 'id'),
            ModelField::integer('integration')->notNull()->references('integration', 'id'),
            ModelField::string('external_id', 64)->notNull(),
            ModelField::string('issue_key', 64)->notNull(),
            ModelField::string('summary', 500)->notNull(),
            ModelField::string('url', 500)->notNull(),
            ModelField::string('project', 255)->notNull(),
            ModelField::string('issue_type', 100)->notNull(),
            ModelField::string('priority', 100),
            ModelField::string('status_id', 64)->notNull(),
            ModelField::string('status', 100)->notNull(),
            ModelField::string('status_category', 20)->notNull(),
            ModelField::string('sprint_id', 64),
            ModelField::string('sprint_name', 255),
            ModelField::string('sprint_state', 20),
            ModelField::boolean('tracked')->notNull(),
            ModelField::datetime('synced_at')->notNull(),
        ]);
        $plan->addUniqueIndex('jira_issue', ['integration', 'external_id']);

        $plan->addColumn('task', ModelField::integer('jira_issue')->references('jira_issue', 'id'));
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropConstraint('task', 'task_ibfk_3');
        $plan->dropColumn('task', 'jira_issue');
        $plan->dropTable('jira_issue');
    }
};
