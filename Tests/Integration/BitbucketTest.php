<?php

namespace Tests\Integration;

use App\Integrations\Bitbucket\BitbucketClient;
use App\Integrations\Bitbucket\BitbucketProvider;
use App\Integrations\IntegrationConfiguration;
use App\Integrations\IntegrationEvents;
use App\Integrations\IntegrationService;
use App\Integrations\PullRequestData;
use App\Integrations\PullRequestProvider;
use App\Integrations\PullRequestSync;
use App\Models\Integration;
use App\Models\PullRequest;
use App\Services\WorkspaceSignals;
use App\Session\SelectedWorkspace;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;
use Cube\Web\Http\HttpMockServer;
use Cube\Web\Http\Response;
use Cube\Web\Websocket\Broadcast\Broadcast;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;

class BitbucketTest extends CubeTestCase
{
    private Broadcast $originalBroadcast;
    private object $broadcast;

    protected function setUp(): void
    {
        parent::setUp();
        Authentication::getInstance()->logout();
        SelectedWorkspace::unset();

        // Never reach the real websocket server
        $this->originalBroadcast = Broadcast::getInstance();
        $this->broadcast = new class(new BroadcastConfiguration()) extends Broadcast {
            public array $emitted = [];

            public array $signals = [];

            public function emit(string $event, array $data): bool
            {
                // Signals to the browsers are kept apart from what the devices receive
                if (in_array($data['type'] ?? null, WorkspaceSignals::SIGNALS, true)) {
                    $this->signals[] = $data;
                    return true;
                }

                $this->emitted[] = $data;

                return true;
            }
        };
        Broadcast::setInstance($this->broadcast);
    }

    protected function tearDown(): void
    {
        Broadcast::setInstance($this->originalBroadcast);
        parent::tearDown();
    }

    private function register(string $email = 'alice@example.com'): void
    {
        $this->postJson('/api/auth/register', ['email' => $email, 'password' => 'correct horse battery'])->assertCreated();
    }

    private function save(array $override = []): array
    {
        return $this->putJson('/api/integrations/bitbucket', [
            'workspace' => 'team',
            'email' => 'me@example.com',
            'token' => 'the-api-token',
            ...$override,
        ])->assertOk()->json();
    }

    private function integration(): Integration
    {
        return Integration::findWhere(['type' => IntegrationService::TYPE_BITBUCKET]);
    }

    private function pr(string $id = '1', array $override = []): PullRequestData
    {
        return new PullRequestData(...[
            'externalId' => $id,
            'repository' => 'team/api',
            'title' => "PR {$id}",
            'author' => 'Alice',
            'state' => PullRequestData::STATE_OPEN,
            'draft' => false,
            'sourceBranch' => "feature-{$id}",
            'targetBranch' => 'main',
            'sourceCommit' => 'abc',
            'url' => "https://bitbucket.org/team/api/pull-requests/{$id}",
            'commentCount' => 0,
            'reviewers' => [],
            ...$override,
        ]);
    }

    private function fakeForge(): object
    {
        return new class implements PullRequestProvider {
            public array $open = [];
            public array $final = [];
            public string $pipeline = PullRequestData::PIPELINE_SUCCESS;
            public int $pipelineCalls = 0;
            public ?\Throwable $failure = null;

            public function openPullRequests(): array
            {
                if ($this->failure)
                    throw $this->failure;

                return $this->open;
            }

            public function pullRequest(string $repository, string $externalId): ?PullRequestData
            {
                return $this->final[$externalId] ?? null;
            }

            public function pipelineStatus(string $repository, string $externalId): string
            {
                ++$this->pipelineCalls;

                return $this->pipeline;
            }
        };
    }

    private function sync(object $forge): void
    {
        $sync = new class(new IntegrationService(), inject(IntegrationEvents::class), $forge) extends PullRequestSync {
            public function __construct(IntegrationService $integrations, IntegrationEvents $events, private object $forge)
            {
                parent::__construct($integrations, $events);
            }

            protected function provider(Integration $integration): PullRequestProvider
            {
                return $this->forge;
            }
        };

        $sync->sync($this->integration());
    }

    private function historyTypes(): array
    {
        return array_column($this->get('/api/history')->assertOk()->json(), 'type');
    }

    /**
     * @return array<string,array> The details of the newest line of each type
     */
    private function historyDetails(): array
    {
        $details = [];
        foreach (array_reverse($this->get('/api/history')->assertOk()->json()) as $line)
            $details[$line['type']] = json_decode($line['details'], true);

        return $details;
    }

    public function testTokenIsEncryptedAndNeverGivenBack()
    {
        $this->register();
        $body = $this->save();

        $this->assertStringNotContainsString('the-api-token', json_encode($body));
        $this->assertStringNotContainsString('the-api-token', $this->integration()->credentials);

        $credentials = $this->integration()->credentials;
        $this->putJson('/api/integrations/bitbucket', ['workspace' => 'team', 'email' => 'other@example.com'])->assertOk();
        $this->assertSame($credentials, $this->integration()->credentials);
    }

    public function testNewIntegrationNeedsAToken()
    {
        $this->register();

        $this->putJson('/api/integrations/bitbucket', ['workspace' => 'team', 'email' => 'me@example.com'])->assertUnprocessableContent();
        $this->putJson('/api/integrations/bitbucket', ['workspace' => '../evil', 'email' => 'me@example.com', 'token' => 'x'])->assertUnprocessableContent();
    }

    public function testFirstSyncIsTheReferenceThenDifferencesAreLogged()
    {
        $this->register();
        $this->save();
        $forge = $this->fakeForge();
        $forge->open = [$this->pr('1')];

        $this->sync($forge);
        $this->assertSame(1, PullRequest::select()->where('integration', $this->integration()->id)->count());
        $this->assertSame([], $this->historyTypes());

        $forge->open = [$this->pr('1', ['commentCount' => 2]), $this->pr('2')];
        $this->sync($forge);
        $this->assertEqualsCanonicalizing(['pr.opened', 'pr.commented'], $this->historyTypes());

        $forge->open = [$this->pr('2')];
        $forge->final = ['1' => $this->pr('1', ['state' => PullRequestData::STATE_MERGED])];
        $this->sync($forge);

        $this->assertSame(PullRequestData::STATE_MERGED, PullRequest::findWhere(['external_id' => '1'])->state);
        $this->assertSame('pr.merged', $this->historyTypes()[0]);

        $forge->open = [];
        $forge->final = ['2' => $this->pr('2', ['state' => PullRequestData::STATE_DECLINED])];
        $this->sync($forge);
        $this->assertSame('pr.declined', $this->historyTypes()[0]);
        $this->assertSame('declined', $this->historyDetails()['pr.declined']['state']);
    }

    public function testEachChangeIsAPreciseEventSentToTheDevices()
    {
        $this->register();
        $this->save();
        $forge = $this->fakeForge();
        $forge->open = [$this->pr('1', ['draft' => true, 'reviewers' => ['u1' => ['name' => 'Bob', 'status' => PullRequestData::REVIEW_PENDING]]])];
        $this->sync($forge);

        $forge->pipeline = PullRequestData::PIPELINE_FAILED;
        $forge->open = [$this->pr('1', [
            'title' => 'Renamed',
            'draft' => false,
            'sourceCommit' => 'def',
            'targetBranch' => 'develop',
            'commentCount' => 3,
            'reviewers' => [
                'u1' => ['name' => 'Bob', 'status' => PullRequestData::REVIEW_APPROVED],
                'u2' => ['name' => 'Carol', 'status' => PullRequestData::REVIEW_CHANGES_REQUESTED],
            ],
        ])];
        $this->sync($forge);

        $this->assertEqualsCanonicalizing([
            'pr.ready', 'pr.pushed', 'pr.retargeted', 'pr.commented', 'pr.review.approved', 'pr.review.changes_requested', 'pr.pipeline.failed',
        ], $this->historyTypes());

        $details = $this->historyDetails();
        $this->assertSame(['source' => 'bitbucket', 'id' => '1', 'repository' => 'team/api', 'title' => 'Renamed', 'url' => 'https://bitbucket.org/team/api/pull-requests/1', 'count' => 3, 'from' => 0, 'to' => 3], $details['pr.commented']);
        $this->assertSame(['success', 'failed'], [$details['pr.pipeline.failed']['from'], $details['pr.pipeline.failed']['to']]);
        $this->assertSame(['main', 'develop'], [$details['pr.retargeted']['from'], $details['pr.retargeted']['to']]);
        $this->assertSame('Bob', $details['pr.review.approved']['reviewer']);
        $this->assertSame('Carol', $details['pr.review.changes_requested']['reviewer']);

        $this->assertEqualsCanonicalizing($this->historyTypes(), array_column($this->broadcast->emitted, 'event'));

        $forge->pipeline = PullRequestData::PIPELINE_SUCCESS;
        $forge->open = [$this->pr('1', ['title' => 'Renamed again', 'sourceCommit' => 'ghi', 'targetBranch' => 'develop', 'commentCount' => 3, 'reviewers' => [
            'u1' => ['name' => 'Bob', 'status' => PullRequestData::REVIEW_APPROVED],
            'u2' => ['name' => 'Carol', 'status' => PullRequestData::REVIEW_CHANGES_REQUESTED],
        ]])];
        $this->sync($forge);
        $this->assertEqualsCanonicalizing(['pr.pushed', 'pr.pipeline.succeeded'], array_slice($this->historyTypes(), 0, 2));
        $this->assertCount(9, $this->historyTypes());
    }

    public function testPipelineIsOnlyAskedWhenItCanHaveChanged()
    {
        $this->register();
        $this->save();
        $forge = $this->fakeForge();
        $forge->open = [$this->pr('1')];

        $this->sync($forge);
        $this->sync($forge);
        $this->assertSame(1, $forge->pipelineCalls);

        $forge->open = [$this->pr('1', ['sourceCommit' => 'def'])];
        $forge->pipeline = PullRequestData::PIPELINE_RUNNING;
        $this->sync($forge);
        $this->sync($forge);
        $this->assertSame(3, $forge->pipelineCalls);
    }

    public function testFailureKeepsTheStoredStateAndReportsTheError()
    {
        $this->register();
        $this->save();
        $forge = $this->fakeForge();
        $forge->open = [$this->pr('1')];
        $this->sync($forge);

        $forge->failure = new \RuntimeException('Bitbucket answered 401');
        $this->sync($forge);

        $this->assertSame('Bitbucket answered 401', $this->integration()->last_error);
        $this->assertSame(1, PullRequest::select()->where('integration', $this->integration()->id)->count());
    }

    public function testReviewersAndWorkspaceIsolation()
    {
        $this->register();
        $this->save();
        $forge = $this->fakeForge();
        $forge->open = [$this->pr('1', ['reviewers' => [
            'u1' => ['name' => 'Bob', 'status' => PullRequestData::REVIEW_APPROVED],
            'u2' => ['name' => 'Carol', 'status' => PullRequestData::REVIEW_PENDING],
        ]])];
        $this->sync($forge);

        $approvals = $this->get('/api/pull-requests/approvals')->assertOk()->json();
        $this->assertEqualsCanonicalizing(['approved', 'pending'], array_column($approvals, 'status'));
        $this->assertCount(1, $this->get('/api/pull-requests')->assertOk()->json());

        Authentication::getInstance()->logout();
        SelectedWorkspace::unset();
        $this->register('bob@example.com');

        $this->assertSame([], $this->get('/api/pull-requests')->assertOk()->json());
        $this->assertSame([], $this->get('/api/pull-requests/approvals')->assertOk()->json());
        $this->get('/api/integrations/bitbucket')->assertNotFound();
    }

    public function testAnotherAccountStartsFromANewReference()
    {
        $this->register();
        $this->save();
        $forge = $this->fakeForge();
        $forge->open = [$this->pr('1')];
        $this->sync($forge);

        $this->putJson('/api/integrations/bitbucket', ['workspace' => 'other', 'email' => 'me@example.com'])->assertOk();
        $this->assertSame([], $this->get('/api/pull-requests')->assertOk()->json());

        $forge->open = [$this->pr('2')];
        $this->sync($forge);
        $this->assertSame([], $this->historyTypes());
    }

    public function testClosedPullRequestsArePurgedAfterTheRetention()
    {
        $this->register();
        $this->save();
        $forge = $this->fakeForge();
        $forge->open = [$this->pr('1'), $this->pr('2')];
        $this->sync($forge);
        $forge->open = [$this->pr('2')];
        $forge->final = ['1' => $this->pr('1', ['state' => PullRequestData::STATE_MERGED])];
        $this->sync($forge);

        $old = date('Y-m-d H:i:s', strtotime('-40 days'));
        foreach (['1', '2'] as $id) {
            $row = PullRequest::findWhere(['external_id' => $id]);
            $row->synced_at = $old;
            $row->save();
        }

        (new IntegrationService())->purgeClosedPullRequests(new IntegrationConfiguration());

        $this->assertNull(PullRequest::findWhere(['external_id' => '1']));
        $this->assertNotNull(PullRequest::findWhere(['external_id' => '2'])); // Still open
    }

    public function testBitbucketAnswersAreTranslated()
    {
        $client = new BitbucketClient('me@example.com', 'token');
        $client->setMockServer(HttpMockServer::fromArray([
            '/user' => Response::json(['uuid' => '{me}']),
            '/workspaces/team/pullrequests/%7Bme%7D' => Response::json(['values' => [[
                'id' => 7,
                'title' => 'Add login',
                'state' => 'OPEN',
                'draft' => true,
                'comment_count' => 3,
                'author' => ['display_name' => 'Alice'],
                'source' => ['branch' => ['name' => 'login'], 'commit' => ['hash' => 'abc123']],
                'destination' => ['branch' => ['name' => 'main'], 'repository' => ['full_name' => 'team/api']],
                'links' => ['html' => ['href' => 'https://bitbucket.org/team/api/pull-requests/7']],
                'participants' => [
                    ['role' => 'REVIEWER', 'approved' => true, 'state' => 'approved', 'user' => ['account_id' => 'u1', 'display_name' => 'Bob']],
                    ['role' => 'REVIEWER', 'approved' => false, 'state' => 'changes_requested', 'user' => ['account_id' => 'u2', 'display_name' => 'Carol']],
                    ['role' => 'REVIEWER', 'approved' => false, 'state' => null, 'user' => ['account_id' => 'u3', 'display_name' => 'Dan']],
                    ['role' => 'PARTICIPANT', 'approved' => false, 'state' => null, 'user' => ['account_id' => 'u4', 'display_name' => 'Eve']],
                ],
            ]]]),
            '/repositories/team/api/pullrequests/7/statuses' => Response::json(['values' => [['state' => 'SUCCESSFUL'], ['state' => 'INPROGRESS']]]),
            '/repositories/team/api/pullrequests/8/statuses' => Response::json(['values' => [['state' => 'SUCCESSFUL'], ['state' => 'FAILED']]]),
            '/repositories/team/api/pullrequests/9/statuses' => Response::json(['values' => []]),
        ]));
        $provider = new BitbucketProvider($client, 'team');

        $pullRequest = $provider->openPullRequests()[0];
        $this->assertSame('7', $pullRequest->externalId);
        $this->assertSame('team/api', $pullRequest->repository);
        $this->assertSame(PullRequestData::STATE_OPEN, $pullRequest->state);
        $this->assertTrue($pullRequest->draft);
        $this->assertSame('login', $pullRequest->sourceBranch);
        $this->assertSame('main', $pullRequest->targetBranch);
        $this->assertSame(3, $pullRequest->commentCount);
        $this->assertSame([
            'u1' => ['name' => 'Bob', 'status' => 'approved'],
            'u2' => ['name' => 'Carol', 'status' => 'changes_requested'],
            'u3' => ['name' => 'Dan', 'status' => 'pending'],
        ], $pullRequest->reviewers);

        $this->assertSame(PullRequestData::PIPELINE_RUNNING, $provider->pipelineStatus('team/api', '7'));
        $this->assertSame(PullRequestData::PIPELINE_FAILED, $provider->pipelineStatus('team/api', '8'));
        $this->assertSame(PullRequestData::PIPELINE_NONE, $provider->pipelineStatus('team/api', '9'));
    }
}
