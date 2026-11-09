<?php

namespace App\Integrations;

use App\Integrations\Bitbucket\BitbucketClient;
use App\Integrations\Bitbucket\BitbucketProvider;
use App\Models\Integration;
use App\Models\PullRequest;
use App\Models\PullRequestApprovals;
use Cube\Data\Database\Database;

use function Cube\decrypt;
use function Cube\encrypt;

class IntegrationService
{
    public const TYPE_BITBUCKET = 'bitbucket';

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

    public function delete(Integration $integration): void
    {
        $this->forgetPullRequests($integration);
        Integration::deleteId($integration->id);
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
