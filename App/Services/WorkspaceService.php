<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Session\SelectedWorkspace;
use Cube\Data\Bunch;
use Cube\Data\Database\Database;
use Cube\Data\Database\Query;

class WorkspaceService
{
    public function __construct(
        protected Database $database,
    ) {}

    /**
     * Create a default workspace for a user who is not the author of any.
     * Being a member of someone else's workspace does not count.
     *
     * Opens its own transaction : Cube cannot nest them, never call it inside one.
     */
    public function createDefault(User $user): void
    {
        if (Workspace::existsWhere(['author' => $user->id]))
            return;

        $failure = $this->database->transaction(function () use ($user) {
            $workspace = Workspace::fromArray([
                'name' => 'Mon workspace',
                'author' => $user->id,
            ])->save();

            WorkspaceMember::fromArray([
                'workspace' => $workspace->id,
                'user' => $user->id,
            ])->save();
        });

        if ($failure)
            throw $failure;
    }

    /**
     * @return Bunch<int,Workspace> The workspaces the user is a member of, oldest membership first
     */
    public function listFor(int $userId): Bunch
    {
        return Bunch::wrap($this->memberships($userId)->fetch())
            ->map(fn (WorkspaceMember $membership) => $membership->_workspace);
    }

    /**
     * Select the first workspace found for the user.
     */
    public function selectFirst(int $userId): ?Workspace
    {
        $workspace = $this->memberships($userId)->first()?->_workspace;
        if (!$workspace) {
            SelectedWorkspace::unset();
            return null;
        }

        SelectedWorkspace::set($workspace->id);

        return $workspace;
    }

    /**
     * @return ?Workspace `null` when the user is not a member of this workspace
     */
    public function select(int $userId, int $workspaceId): ?Workspace
    {
        if (!$this->isMember($userId, $workspaceId))
            return null;

        SelectedWorkspace::set($workspaceId);

        return Workspace::find($workspaceId);
    }

    /**
     * The selected workspace, or the first one found when the user no longer belongs to it.
     */
    public function current(int $userId): ?Workspace
    {
        $selected = SelectedWorkspace::get();

        return $selected && $this->isMember($userId, $selected)
            ? Workspace::find($selected)
            : $this->selectFirst($userId);
    }

    private function isMember(int $userId, int $workspaceId): bool
    {
        return WorkspaceMember::existsWhere(['user' => $userId, 'workspace' => $workspaceId]);
    }

    private function memberships(int $userId): Query
    {
        return WorkspaceMember::select(['_workspace'])
            ->where('user', $userId)
            ->order('id', 'ASC');
    }
}
