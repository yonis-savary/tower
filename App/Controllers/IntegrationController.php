<?php

namespace App\Controllers;

use App\Integrations\IntegrationService;
use App\Integrations\Jira\JiraIssueSync;
use App\Integrations\PullRequestSync;
use App\Middlewares\IsAuthenticated;
use App\Requests\SaveBitbucketRequest;
use App\Requests\SaveJiraRequest;
use App\Resources\IntegrationResource;
use App\Resources\JiraIntegrationResource;
use App\Services\WorkspaceService;
use Cube\Data\OpenAPI\Attributes\Endpoint;
use Cube\Data\OpenAPI\Attributes\NoContentResponse;
use Cube\Data\OpenAPI\Attributes\RawResponse;
use Cube\Data\OpenAPI\Attributes\ResourceResponse;
use Cube\Security\Authentication;
use Cube\Web\Controller;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Http\StatusCode;
use Cube\Web\Router\Route;
use Cube\Web\Router\Router;

class IntegrationController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected WorkspaceService $workspaces,
        protected IntegrationService $integrations,
        protected PullRequestSync $sync,
        protected JiraIssueSync $jiraSync,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/integrations/bitbucket', [self::class, 'show']),
            Route::put('/integrations/bitbucket', [self::class, 'save']),
            Route::delete('/integrations/bitbucket', [self::class, 'delete']),
            Route::post('/integrations/bitbucket/sync', [self::class, 'synchronize']),
            Route::get('/integrations/jira', [self::class, 'showJira']),
            Route::put('/integrations/jira', [self::class, 'saveJira']),
            Route::delete('/integrations/jira', [self::class, 'deleteJira']),
            Route::post('/integrations/jira/sync', [self::class, 'synchronizeJira']),
        ]);
    }

    #[Endpoint('Get Bitbucket integration', 'The Bitbucket integration of the current workspace, without its token', 'getBitbucketIntegration')]
    #[ResourceResponse(IntegrationResource::class, description: 'The integration')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Integration not found'], responseCode: StatusCode::NOT_FOUND, description: 'The current workspace has no Bitbucket integration')]
    public function show(Request $request): Response
    {
        $integration = $this->integrations->findBitbucket($this->currentWorkspaceId());

        return $integration ? Response::json(IntegrationResource::of($integration)) : $this->notFound();
    }

    #[Endpoint('Save Bitbucket integration', 'Creates or updates the Bitbucket integration of the current workspace. Without token, the current one is kept', 'saveBitbucketIntegration')]
    #[ResourceResponse(IntegrationResource::class, description: 'The integration')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'A token is required'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'No token was given for a new integration, or an invalid field')]
    public function save(SaveBitbucketRequest $request): Response
    {
        $workspace = $this->currentWorkspaceId();
        $token = $request->validated('token') ?: null;

        if (null === $token && !$this->integrations->findBitbucket($workspace))
            return Response::json(['error' => 'A token is required'], StatusCode::UNPROCESSABLE_CONTENT);

        $integration = $this->integrations->saveBitbucket($workspace, $request->validated('workspace'), $request->validated('email'), $token);

        return Response::json(IntegrationResource::of($integration));
    }

    #[Endpoint('Delete Bitbucket integration', 'Deletes the Bitbucket integration of the current workspace, its token and its pull requests', 'deleteBitbucketIntegration')]
    #[NoContentResponse('The integration is deleted')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Integration not found'], responseCode: StatusCode::NOT_FOUND, description: 'The current workspace has no Bitbucket integration')]
    public function delete(Request $request): Response
    {
        $integration = $this->integrations->findBitbucket($this->currentWorkspaceId());
        if (!$integration)
            return $this->notFound();

        $this->integrations->delete($integration);

        return Response::noContent();
    }

    #[Endpoint('Synchronize Bitbucket integration', 'Reads Bitbucket now instead of waiting for the next scheduled run. A failure is reported in `last_error`', 'syncBitbucketIntegration')]
    #[ResourceResponse(IntegrationResource::class, description: 'The integration after the run')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Integration not found'], responseCode: StatusCode::NOT_FOUND, description: 'The current workspace has no Bitbucket integration')]
    public function synchronize(Request $request): Response
    {
        $integration = $this->integrations->findBitbucket($this->currentWorkspaceId());
        if (!$integration)
            return $this->notFound();

        $this->sync->sync($integration);

        return Response::json(IntegrationResource::of($integration));
    }

    #[Endpoint('Get Jira integration', 'The Jira integration of the current workspace, without its token', 'getJiraIntegration')]
    #[ResourceResponse(JiraIntegrationResource::class, description: 'The integration')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Integration not found'], responseCode: StatusCode::NOT_FOUND, description: 'The current workspace has no Jira integration')]
    public function showJira(Request $request): Response
    {
        $integration = $this->integrations->findJira($this->currentWorkspaceId());

        return $integration ? Response::json(JiraIntegrationResource::of($integration)) : $this->notFound();
    }

    #[Endpoint('Save Jira integration', 'Creates or updates the Jira integration of the current workspace. Without token, the current one is kept', 'saveJiraIntegration')]
    #[ResourceResponse(JiraIntegrationResource::class, description: 'The integration')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'A token is required'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'No token was given for a new integration, or an invalid field')]
    public function saveJira(SaveJiraRequest $request): Response
    {
        $workspace = $this->currentWorkspaceId();
        $token = $request->validated('token') ?: null;

        if (null === $token && !$this->integrations->findJira($workspace))
            return Response::json(['error' => 'A token is required'], StatusCode::UNPROCESSABLE_CONTENT);

        $integration = $this->integrations->saveJira($workspace, $request->validated('site'), $request->validated('email'), $request->validated('board'), $token);

        return Response::json(JiraIntegrationResource::of($integration));
    }

    #[Endpoint('Delete Jira integration', 'Deletes the Jira integration of the current workspace, its token and its issues. Their tasks stay, unlinked', 'deleteJiraIntegration')]
    #[NoContentResponse('The integration is deleted')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Integration not found'], responseCode: StatusCode::NOT_FOUND, description: 'The current workspace has no Jira integration')]
    public function deleteJira(Request $request): Response
    {
        $integration = $this->integrations->findJira($this->currentWorkspaceId());
        if (!$integration)
            return $this->notFound();

        $this->integrations->delete($integration);

        return Response::noContent();
    }

    #[Endpoint('Synchronize Jira integration', 'Reads Jira now instead of waiting for the next scheduled run. A failure is reported in `last_error`', 'syncJiraIntegration')]
    #[ResourceResponse(JiraIntegrationResource::class, description: 'The integration after the run')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Integration not found'], responseCode: StatusCode::NOT_FOUND, description: 'The current workspace has no Jira integration')]
    public function synchronizeJira(Request $request): Response
    {
        $integration = $this->integrations->findJira($this->currentWorkspaceId());
        if (!$integration)
            return $this->notFound();

        $this->jiraSync->sync($integration);

        return Response::json(JiraIntegrationResource::of($integration));
    }

    private function notFound(): Response
    {
        return Response::json(['error' => 'Integration not found'], StatusCode::NOT_FOUND);
    }

    private function currentWorkspaceId(): int
    {
        return $this->workspaces->current($this->authentication->userId())->id;
    }
}
