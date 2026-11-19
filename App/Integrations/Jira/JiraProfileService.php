<?php

namespace App\Integrations\Jira;

use App\Models\Integration;
use App\Models\JiraIssue;
use App\Models\JiraIssueProfile;
use App\Models\JiraProfile;
use App\Models\Task;
use Cube\Data\Bunch;
use Cube\Data\Database\Database;
use Cube\Data\Database\Query;

class JiraProfileService
{
    public function __construct(
        protected Database $database,
    ) {}

    /**
     * @return Bunch<int,JiraProfile> Oldest first
     */
    public function listFor(int $workspace): Bunch
    {
        return Bunch::wrap(JiraProfile::select()->where('workspace', $workspace)->order('id', 'ASC')->fetch());
    }

    public function find(int $workspace, int $id): ?JiraProfile
    {
        $profile = JiraProfile::find($id);

        return $profile && $profile->workspace === $workspace ? $profile : null;
    }

    public function create(Integration $integration, ?string $name, int $board, ?string $jql): JiraProfile
    {
        return (new JiraProfile([
            'workspace' => $integration->workspace,
            'integration' => $integration->id,
            'name' => $name,
            'board' => $board,
            'jql' => $jql,
            'columns' => '[]',
            'done_statuses' => '[]',
            'created_at' => date('Y-m-d H:i:s'),
        ]))->save();
    }

    /**
     * Opens its own transaction : Cube cannot nest them, never call it inside one.
     */
    public function update(JiraProfile $profile, ?string $name, int $board, ?string $jql): JiraProfile
    {
        $failure = $this->database->transaction(function () use ($profile, $name, $board, $jql) {
            // Another board or filter is read again from a new reference, as a new profile would be
            if ($profile->board !== $board || $profile->jql !== $jql) {
                $this->forgetIssuesOf($profile);
                $profile->board_name = null;
                $profile->columns = '[]';
                $profile->done_statuses = '[]';
                $profile->synced_at = null;
            }

            $profile->name = $name;
            $profile->board = $board;
            $profile->jql = $jql;
            $profile->save();
        });

        if ($failure)
            throw $failure;

        return $profile;
    }

    /**
     * Opens its own transaction : Cube cannot nest them, never call it inside one.
     */
    public function delete(JiraProfile $profile): void
    {
        $failure = $this->database->transaction(function () use ($profile) {
            $this->forgetIssuesOf($profile);
            JiraProfile::deleteId($profile->id);
        });

        if ($failure)
            throw $failure;
    }

    /**
     * @return array<int,list<int>> The ids of the profiles showing each issue, by issue id
     */
    public function profilesByIssue(int $integration): array
    {
        $links = JiraIssueProfile::select()
            ->whereIn('jira_profile', Query::select('jira_profile')->selectField('id', 'jira_profile')->where('integration', $integration, table: 'jira_profile'))
            ->fetch();

        $profiles = [];
        foreach ($links as $link)
            $profiles[$link->jira_issue][] = $link->jira_profile;

        return $profiles;
    }

    // The issues no other profile shows leave silently : the user changed what is followed, nothing happened in Jira
    private function forgetIssuesOf(JiraProfile $profile): void
    {
        JiraIssueProfile::delete()->where('jira_profile', $profile->id)->fetch();

        $orphans = JiraIssue::select()
            ->where('integration', $profile->integration)
            ->where('tracked', true)
            ->whereNotIn('id', Query::select('jira_issue_profile')->selectField('jira_issue', 'jira_issue_profile'))
            ->fetch();

        $leaving = [];
        foreach ($orphans as $issue) {
            if (!Task::findWhere(['jira_issue' => $issue->id])) {
                $leaving[] = $issue->id;
                continue;
            }

            // A task keeps showing the status of its issue : the row stays, only out of the profiles
            $issue->tracked = false;
            $issue->parent = null;
            $issue->save();
        }

        if ([] === $leaving)
            return;

        // A parent can only be deleted once no subtask points to it
        JiraIssue::update()->set('parent', null)->whereIn('id', $leaving)->fetch();
        JiraIssue::delete()->whereIn('id', $leaving)->fetch();
    }
}
