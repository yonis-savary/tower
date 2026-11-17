<?php

namespace App\Services;

use App\Models\NotificationRule;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Session\SelectedWorkspace;
use Cube\Data\Bunch;
use Cube\Data\Database\Database;
use Cube\Data\Database\Query;

class WorkspaceService
{
    public const SECTIONS = ['tasks', 'jira', 'merge_requests', 'events', 'automation', 'webhooks'];

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

        $this->create($user->id, 'Mon workspace');
    }

    /**
     * Opens its own transaction : Cube cannot nest them, never call it inside one.
     */
    public function create(int $userId, string $name): Workspace
    {
        $workspace = null;
        $failure = $this->database->transaction(function () use ($userId, $name, &$workspace) {
            $workspace = Workspace::fromArray(['name' => $name, 'author' => $userId])->save();
            WorkspaceMember::fromArray(['workspace' => $workspace->id, 'user' => $userId])->save();
        });

        if ($failure)
            throw $failure;

        return $workspace;
    }

    /**
     * @param ?list<string> $sections null keeps the current ones
     */
    public function update(Workspace $workspace, string $name, ?string $accentColor, ?array $sections = null): Workspace
    {
        $workspace->name = $name;
        $workspace->accent_color = null === $accentColor ? null : strtolower($accentColor);
        if (null !== $sections)
            $workspace->sections = json_encode(array_values(array_intersect(self::SECTIONS, $sections)), JSON_THROW_ON_ERROR);

        return $workspace->save();
    }

    /**
     * @return ?Workspace `null` when the user is not a member of this workspace
     */
    public function findFor(int $userId, int $workspaceId): ?Workspace
    {
        return $this->isMember($userId, $workspaceId) ? Workspace::find($workspaceId) : null;
    }

    /**
     * @return Bunch<int,User> Oldest membership first
     */
    public function members(Workspace $workspace): Bunch
    {
        return Bunch::wrap(WorkspaceMember::select(['_user'])->where('workspace', $workspace->id)->order('id', 'ASC')->fetch())
            ->map(fn (WorkspaceMember $membership) => $membership->_user);
    }

    /**
     * Adding someone already member changes nothing.
     *
     * @return ?User `null` when no active account has this e-mail
     */
    public function addMember(Workspace $workspace, string $email): ?User
    {
        $user = User::findWhere(['email' => $email]);
        if (!$user || null !== $user->disabled_at)
            return null;

        if (!$this->isMember($user->id, $workspace->id))
            WorkspaceMember::fromArray(['workspace' => $workspace->id, 'user' => $user->id])->save();

        return $user;
    }

    /**
     * @return bool false when the user is its last member : a workspace cannot be left empty, as it cannot be deleted
     */
    public function removeMember(Workspace $workspace, int $userId): bool
    {
        if (WorkspaceMember::select()->where('workspace', $workspace->id)->count() <= 1)
            return false;

        WorkspaceMember::deleteWhere(['workspace' => $workspace->id, 'user' => $userId]);
        // What they asked to be notified of here is theirs only
        NotificationRule::deleteWhere(['workspace' => $workspace->id, 'user' => $userId]);
        PushSubscription::deleteWhere(['workspace' => $workspace->id, 'user' => $userId]);

        return true;
    }

    /**
     * @return list<string> In the order of SECTIONS ; a workspace never configured has them all
     */
    public static function sectionsOf(Workspace $workspace): array
    {
        return null === $workspace->sections ? self::SECTIONS : json_decode($workspace->sections, true);
    }

    public function isMember(int $userId, int $workspaceId): bool
    {
        return WorkspaceMember::existsWhere(['user' => $userId, 'workspace' => $workspaceId]);
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

    private function memberships(int $userId): Query
    {
        return WorkspaceMember::select(['_workspace'])
            ->where('user', $userId)
            ->order('id', 'ASC');
    }
}
