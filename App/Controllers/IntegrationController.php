<?php

namespace App\Controllers;

use App\Domain\Integrations\IntegrationService;
use App\Domain\Integrations\Jira\JiraIssueSync;
use App\Domain\Integrations\Jira\JiraProfileService;
use App\Domain\Integrations\PullRequestSync;
use App\Middlewares\IsAuthenticated;
use App\Requests\SaveBitbucketRequest;
use App\Requests\SaveJiraProfileRequest;
use App\Requests\SaveJiraRequest;
use App\Resources\IntegrationResource;
use App\Resources\JiraIntegrationResource;
use App\Resources\JiraProfileResource;
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

use function App\currentWorkspaceId;

class IntegrationController extends Controller
{
    public function __construct(
        protected IntegrationService $integrations,
        protected PullRequestSync $sync,
        protected JiraIssueSync $jiraSync,
        protected JiraProfileService $jiraProfiles,
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
            Route::post('/integrations/jira/profiles', [self::class, 'createJiraProfile']),
            Route::put('/integrations/jira/profiles/{int:profile}', [self::class, 'updateJiraProfile']),
            Route::delete('/integrations/jira/profiles/{int:profile}', [self::class, 'deleteJiraProfile']),
        ]);
    }

    #[Endpoint('Get Bitbucket integration', 'The Bitbucket integration of the current workspace, without its token', 'getBitbucketIntegration')]
    #[ResourceResponse(IntegrationResource::class, description: 'The integration')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Integration not found'], responseCode: StatusCode::NOT_FOUND, description: 'The current workspace has no Bitbucket integration')]
    public function show(Request $request): Response
    {
        $integration = $this->integrations->findBitbucket(currentWorkspaceId());

        return $integration ? Response::json(IntegrationResource::of($integration)) : $this->notFound();
    }

    #[Endpoint('Save Bitbucket integration', 'Creates or updates the Bitbucket integration of the current workspace. Without token, the current one is kept', 'saveBitbucketIntegration')]
    #[ResourceResponse(IntegrationResource::class, description: 'The integration')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'A token is required'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'No token was given for a new integration, or an invalid field')]
    public function save(SaveBitbucketRequest $request): Response
    {
        $validated = $request->validated();
        $workspace = currentWorkspaceId();
        $token = ($validated['token'] ?? null) ?: null;

        if (null === $token && !$this->integrations->findBitbucket($workspace))
            return Response::json(['error' => 'A token is required'], StatusCode::UNPROCESSABLE_CONTENT);

        $integration = $this->integrations->saveBitbucket($workspace, $validated['workspace'], $validated['email'], $token);

        return Response::json(IntegrationResource::of($integration));
    }

    #[Endpoint('Delete Bitbucket integration', 'Deletes the Bitbucket integration of the current workspace, its token and its pull requests', 'deleteBitbucketIntegration')]
    #[NoContentResponse('The integration is deleted')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Integration not found'], responseCode: StatusCode::NOT_FOUND, description: 'The current workspace has no Bitbucket integration')]
    public function delete(Request $request): Response
    {
        $integration = $this->integrations->findBitbucket(currentWorkspaceId());
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
        $integration = $this->integrations->findBitbucket(currentWorkspaceId());
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
        $integration = $this->integrations->findJira(currentWorkspaceId());

        return $integration ? Response::json(JiraIntegrationResource::of($integration)) : $this->notFound();
    }

    #[Endpoint('Save Jira integration', 'Creates or updates the Jira integration of the current workspace. Without token, the current one is kept', 'saveJiraIntegration')]
    #[ResourceResponse(JiraIntegrationResource::class, description: 'The integration')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'A token is required'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'No token was given for a new integration, or an invalid field')]
    public function saveJira(SaveJiraRequest $request): Response
    {
        $validated = $request->validated();
        $workspace = currentWorkspaceId();
        $token = ($validated['token'] ?? null) ?: null;

        if (null === $token && !$this->integrations->findJira($workspace))
            return Response::json(['error' => 'A token is required'], StatusCode::UNPROCESSABLE_CONTENT);

        $integration = $this->integrations->saveJira($workspace, $validated['site'], $validated['email'], $token);

        return Response::json(JiraIntegrationResource::of($integration));
    }

    #[Endpoint('Delete Jira integration', 'Deletes the Jira integration of the current workspace, its token, its profiles and its issues. Their tasks stay, unlinked', 'deleteJiraIntegration')]
    #[NoContentResponse('The integration is deleted')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Integration not found'], responseCode: StatusCode::NOT_FOUND, description: 'The current workspace has no Jira integration')]
    public function deleteJira(Request $request): Response
    {
        $integration = $this->integrations->findJira(currentWorkspaceId());
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
        $integration = $this->integrations->findJira(currentWorkspaceId());
        if (!$integration)
            return $this->notFound();

        $this->jiraSync->sync($integration);

        return Response::json(JiraIntegrationResource::of($integration));
    }

    #[Endpoint('Create Jira profile', 'Adds a profile to the Jira integration of the current workspace : a board to follow, narrowed by an optional JQL condition. Without name, the profile shows the name of its board. Its issues are read on the next sync, without being announced', 'createJiraProfile')]
    #[ResourceResponse(JiraProfileResource::class, responseCode: StatusCode::CREATED, description: 'The profile')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Integration not found'], responseCode: StatusCode::NOT_FOUND, description: 'The current workspace has no Jira integration')]
    public function createJiraProfile(SaveJiraProfileRequest $request): Response
    {
        $validated = $request->validated();
        $integration = $this->integrations->findJira(currentWorkspaceId());
        if (!$integration)
            return $this->notFound();

        $profile = $this->jiraProfiles->create($integration, self::textOrNull($validated['name'] ?? null), $validated['board'], self::textOrNull($validated['jql'] ?? null));

        return Response::json(JiraProfileResource::of($profile), StatusCode::CREATED);
    }

    #[Endpoint('Update Jira profile', 'Changes the name, the board and the JQL condition of a Jira profile. Another board or condition is read again from a new reference : the issues no other profile shows leave without being announced', 'updateJiraProfile')]
    #[ResourceResponse(JiraProfileResource::class, description: 'The profile')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Profile not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such profile in the current workspace')]
    public function updateJiraProfile(SaveJiraProfileRequest $request, int $profile): Response
    {
        $validated = $request->validated();
        $found = $this->jiraProfiles->find(currentWorkspaceId(), $profile);
        if (!$found)
            return $this->profileNotFound();

        $updated = $this->jiraProfiles->update($found, self::textOrNull($validated['name'] ?? null), $validated['board'], self::textOrNull($validated['jql'] ?? null));

        return Response::json(JiraProfileResource::of($updated));
    }

    #[Endpoint('Delete Jira profile', 'Deletes a Jira profile. The issues no other profile shows leave without being announced, those linked to a task stay', 'deleteJiraProfile')]
    #[NoContentResponse('The profile is deleted')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Profile not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such profile in the current workspace')]
    public function deleteJiraProfile(Request $request, int $profile): Response
    {
        $found = $this->jiraProfiles->find(currentWorkspaceId(), $profile);
        if (!$found)
            return $this->profileNotFound();

        $this->jiraProfiles->delete($found);

        return Response::noContent();
    }

    private static function textOrNull(?string $text): ?string
    {
        $trimmed = trim((string) $text);

        return '' === $trimmed ? null : $trimmed;
    }

    private function profileNotFound(): Response
    {
        return Response::json(['error' => 'Profile not found'], StatusCode::NOT_FOUND);
    }

    private function notFound(): Response
    {
        return Response::json(['error' => 'Integration not found'], StatusCode::NOT_FOUND);
    }
}
