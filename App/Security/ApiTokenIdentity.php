<?php

namespace App\Security;

use App\Models\ApiToken;
use App\Models\User;
use App\Services\HistoryActor;
use App\Services\HistoryService;
use App\Session\MemorySession;
use App\Session\SelectedWorkspace;
use Cube\Env\Session;
use Cube\Env\Session\SessionConfiguration;
use Cube\Security\Authentication;
use Cube\Security\Authentication\AuthenticationConfiguration;

class ApiTokenIdentity
{
    public function __construct(
        protected AuthenticationConfiguration $configuration,
    ) {}

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function actAs(ApiToken $token, callable $callback): mixed
    {
        $authentication = new ApiTokenAuthentication($this->configuration);
        $authentication->login(User::find($token->user));

        $session = new Session(new SessionConfiguration(driver: new MemorySession()));
        SelectedWorkspace::set($token->workspace, $session);

        $actor = new HistoryActor(HistoryService::ORIGIN_MCP, $token->name);

        return Session::withInstance($session, fn () => Authentication::withInstance($authentication, fn () => $actor->asGlobalInstance($callback)));
    }
}
