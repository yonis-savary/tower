<?php

namespace App\Domain\Account;

use App\Domain\Event\EventAnnouncer;
use App\Domain\History\HistoryService;
use App\Domain\Workspace\WorkspaceService;
use App\Models\User;

class LoginHistory
{
    public const LOGGED_IN_TYPE = 'user.logged_in';
    public const LOGIN_FAILED_TYPE = 'user.login_failed';

    public function __construct(
        protected HistoryService $history,
        protected EventAnnouncer $announcer,
        protected WorkspaceService $workspaces,
    ) {}

    public function loggedIn(User $user): void
    {
        $this->announce($user, self::LOGGED_IN_TYPE, $user->id);
    }

    public function failed(string $email): void
    {
        if (!$user = User::findWhere(['email' => $email]))
            return;

        // Whoever tried is unknown : the line names the account, not an author
        $this->announce($user, self::LOGIN_FAILED_TYPE, null);
    }

    private function announce(User $user, string $type, ?int $author): void
    {
        $payload = json_encode(['user' => $user->id, 'email' => $user->email], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($this->workspaces->listFor($user->id) as $workspace) {
            $line = $this->history->log($workspace->id, $type, ['email' => $user->email], HistoryService::ORIGIN_USER, $author);
            $this->announcer->announce($workspace->id, $line->id, $type, $payload);
        }
    }
}
