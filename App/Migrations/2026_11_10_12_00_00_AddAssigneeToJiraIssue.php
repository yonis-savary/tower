<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    public function up(Plan $plan, Database $database)
    {
        $plan->addColumn('jira_issue', ModelField::string('assignee_id', 128));
        $plan->addColumn('jira_issue', ModelField::string('assignee_name', 255));
        $plan->addColumn('jira_issue', ModelField::string('assignee_avatar', 500));
    }

    public function down(Plan $plan, Database $database)
    {
        $plan->dropColumn('jira_issue', 'assignee_avatar');
        $plan->dropColumn('jira_issue', 'assignee_name');
        $plan->dropColumn('jira_issue', 'assignee_id');
    }
};
