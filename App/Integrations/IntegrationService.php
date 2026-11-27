<?php

namespace App\Integrations;

use App\Integrations\Bitbucket\BitbucketClient;
use App\Integrations\Bitbucket\BitbucketProvider;
use App\Integrations\Jira\JiraClient;
use App\Integrations\Jira\JiraProvider;
use App\Models\Integration;
use App\Models\JiraIssue;
use App\Models\JiraIssueProfile;
use App\Models\JiraProfile;
use App\Models\PullRequest;
use App\Models\PullRequestApprovals;
use Cube\Data\Database\Database;
use Cube\Data\Database\Query;

use function Cube\decrypt;
use function Cube\encrypt;

class IntegrationService
{
    public const TYPE_BITBUCKET = 'bitbucket';
    public const TYPE_JIRA = 'jira';

    public function findBitbucket(int $workspace): ?Integration
    {
        return Integration::findWhere(['workspace' => $workspace, 'type' => self::TYPE_BITBUCKET]);
    }

    /**
     * @param ?string $token null keeps the current one
     */
    public function saveBitbucket(int $workspace, string $bitbucketWorkspace, string $email, #[\SensitiveParameter] ?string $token): Integration
    {
        $existing = $this->findBitbucket($workspace);
        $integration = $existing ?? new Integration([
            'workspace' => $workspace,
            'type' => self::TYPE_BITBUCKET,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $settings = $existing ? $this->settingsOf($existing) : [];

        // Another account or workspace : what is stored describes someone else, and the next run is a new reference
        if ($existing && ($settings['workspace'] !== $bitbucketWorkspace || $settings['email'] !== $email)) {
            $this->forgetPullRequests($integration);
            $integration->last_synced_at = null;
        }

        $integration->settings = json_encode(['workspace' => $bitbucketWorkspace, 'email' => $email], JSON_THROW_ON_ERROR);
        if (null !== $token)
            $integration->credentials = encrypt($token);

        return $integration->save();
    }

    public function findJira(int $workspace): ?Integration
    {
        return Integration::findWhere(['workspace' => $workspace, 'type' => self::TYPE_JIRA]);
    }

    /**
     * @param string  $site  https://<name>.atlassian.net
     * @param ?string $token null keeps the current one
     */
    public function saveJira(int $workspace, string $site, string $email, #[\SensitiveParameter] ?string $token): Integration
    {
        $existing = $this->findJira($workspace);
        $integration = $existing ?? new Integration([
            'workspace' => $workspace,
            'type' => self::TYPE_JIRA,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $settings = $existing ? $this->settingsOf($existing) : [];

        // Another account or site : what is stored describes someone else, and the next run is a new reference
        if ($existing && ($settings['site'] !== $site || $settings['email'] !== $email)) {
            // The boards of the profiles are numbered by their site
            $settings['site'] === $site ? $this->forgetIssues($integration) : $this->forgetProfiles($integration);
            $integration->last_synced_at = null;
            $settings = [];
        }

        $integration->settings = json_encode([...$settings, 'site' => $site, 'email' => $email], JSON_THROW_ON_ERROR);
        if (null !== $token)
            $integration->credentials = encrypt($token);

        return $integration->save();
    }

    public function delete(Integration $integration): void
    {
        match ($integration->type) {
            self::TYPE_BITBUCKET => $this->forgetPullRequests($integration),
            self::TYPE_JIRA => $this->forgetProfiles($integration),
        };
        Integration::deleteId($integration->id);
    }

    private function forgetProfiles(Integration $integration): void
    {
        $this->forgetIssues($integration);
        JiraProfile::deleteWhere(['integration' => $integration->id]);
    }

    private function forgetIssues(Integration $integration): void
    {
        $profiles = Query::select('jira_profile')
            ->selectField('id', 'jira_profile')
            ->where('integration', $integration->id, table: 'jira_profile');
        JiraIssueProfile::delete()
            ->whereIn('jira_profile', $profiles)
            ->fetch();
        Database::getInstance()->exec('UPDATE task SET jira_issue = NULL WHERE jira_issue IN (SELECT id FROM jira_issue WHERE integration = {})', [$integration->id]);
        Database::getInstance()->exec('UPDATE jira_issue SET parent = NULL WHERE integration = {}', [$integration->id]);
        JiraIssue::deleteWhere(['integration' => $integration->id]);
    }

    private function forgetPullRequests(Integration $integration): void
    {
        Database::getInstance()->exec('DELETE FROM pull_request_approvals WHERE pull_request IN (SELECT id FROM pull_request WHERE integration = {})', [$integration->id]);
        PullRequest::deleteWhere(['integration' => $integration->id]);
    }

    public function settingsOf(Integration $integration): array
    {
        return json_decode($integration->settings, true) ?? [];
    }

    public function jiraAccountOf(Integration $integration): ?string
    {
        return $this->settingsOf($integration)['account'] ?? null;
    }

    public function jiraProviderFor(Integration $integration): JiraProvider
    {
        $settings = $this->settingsOf($integration);

        return new JiraProvider(new JiraClient($settings['site'], $settings['email'], decrypt($integration->credentials)));
    }

    public function providerFor(Integration $integration): PullRequestProvider
    {
        $settings = $this->settingsOf($integration);

        return new BitbucketProvider(
            new BitbucketClient($settings['email'], decrypt($integration->credentials)),
            $settings['workspace'],
        );
    }

    /**
     * Forgets the pull requests closed for longer than the retention.
     */
    public function purgeClosedPullRequests(IntegrationConfiguration $configuration): void
    {
        $limit = date('Y-m-d H:i:s', strtotime("-{$configuration->closedPullRequestRetentionDays} days"));
        $expired = 'SELECT id FROM pull_request WHERE state <> {} AND synced_at < {}';
        $values = [PullRequestData::STATE_OPEN, $limit];
        $database = Database::getInstance();

        $database->exec("DELETE FROM pull_request_approvals WHERE pull_request IN ({$expired})", $values);
        $database->exec('DELETE FROM pull_request WHERE state <> {} AND synced_at < {}', $values);
    }
}
