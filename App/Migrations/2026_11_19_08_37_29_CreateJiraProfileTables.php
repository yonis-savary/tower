<?php

use Cube\Data\Database\Database;
use Cube\Data\Database\Migration\Migration;
use Cube\Data\Database\Migration\Plan;
use Cube\Data\Models\ModelField;

return new class extends Migration
{
    private const MOVED_SETTINGS = ['board', 'columns', 'done_statuses'];

    public function up(Plan $plan, Database $database)
    {
        $plan->create('jira_profile', [
            ModelField::id(),
            ModelField::integer('workspace')->notNull()->references('workspace', 'id'),
            ModelField::integer('integration')->notNull()->references('integration', 'id'),
            ModelField::string('name', 100),
            ModelField::integer('board')->notNull(),
            ModelField::string('board_name', 255),
            ModelField::string('jql', 1000),
            ModelField::string('columns')->notNull(),
            ModelField::string('done_statuses')->notNull(),
            ModelField::datetime('synced_at'),
            ModelField::datetime('created_at')->notNull(),
        ]);

        $plan->create('jira_issue_profile', [
            ModelField::id(),
            ModelField::integer('jira_issue')->notNull()->references('jira_issue', 'id'),
            ModelField::integer('jira_profile')->notNull()->references('jira_profile', 'id'),
        ]);
        $plan->addUniqueIndex('jira_issue_profile', ['jira_issue', 'jira_profile']);

        foreach ($database->query("SELECT * FROM integration WHERE type = 'jira'") as $integration)
            $this->moveBoardToProfile($database, $integration);
    }

    // The configured board becomes the first profile, already synchronized : its issues are not announced again
    private function moveBoardToProfile(Database $database, array $integration): void
    {
        $settings = json_decode($integration['settings'], true) ?? [];
        if (isset($settings['board'])) {
            $database->exec(
                'INSERT INTO jira_profile (workspace, integration, board, columns, done_statuses, synced_at, created_at) VALUES ({}, {}, {}, {}, {}, {}, {})',
                [$integration['workspace'], $integration['id'], $settings['board'], json_encode($settings['columns'] ?? []), json_encode($settings['done_statuses'] ?? []), $integration['last_synced_at'], date('Y-m-d H:i:s')]
            );
            $database->exec(
                'INSERT INTO jira_issue_profile (jira_issue, jira_profile) SELECT id, {} FROM jira_issue WHERE integration = {} AND tracked = TRUE',
                [$database->lastInsertId(), $integration['id']]
            );
        }

        $database->exec('UPDATE integration SET settings = {} WHERE id = {}', [json_encode(array_diff_key($settings, array_flip(self::MOVED_SETTINGS))), $integration['id']]);
    }

    public function down(Plan $plan, Database $database)
    {
        foreach ($database->query("SELECT * FROM integration WHERE type = 'jira'") as $integration) {
            $profile = $database->query('SELECT * FROM jira_profile WHERE integration = {} ORDER BY id LIMIT 1', [$integration['id']])[0] ?? null;
            if (!$profile)
                continue;

            $settings = [
                ...json_decode($integration['settings'], true) ?? [],
                'board' => (int) $profile['board'],
                'columns' => json_decode($profile['columns'], true),
                'done_statuses' => json_decode($profile['done_statuses'], true),
            ];
            $database->exec('UPDATE integration SET settings = {} WHERE id = {}', [json_encode($settings), $integration['id']]);
        }

        $plan->dropTable('jira_issue_profile');
        $plan->dropTable('jira_profile');
    }
};
