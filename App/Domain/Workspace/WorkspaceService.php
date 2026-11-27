<?php

namespace App\Domain\Workspace;

use App\Domain\AccessKey\AccessKeyService;
use App\Domain\Documentation\DocumentationImages;
use App\Domain\Documentation\DocumentationService;
use App\Domain\History\HistoryActor;
use App\Domain\Integrations\IntegrationService;
use App\Models\AccessKey;
use App\Models\AgendaItem;
use App\Models\AgendaItemException;
use App\Models\AgendaItemParticipant;
use App\Models\ApiToken;
use App\Models\ConfigDownload;
use App\Models\DocImage;
use App\Models\EventDelivery;
use App\Models\History;
use App\Models\Integration;
use App\Models\NotificationRule;
use App\Models\PushSubscription;
use App\Models\QuickAction;
use App\Models\ScheduledEvent;
use App\Models\Sprint;
use App\Models\SprintLink;
use App\Models\Task;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookCall;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Session\SelectedWorkspace;
use Cube\Data\Bunch;
use Cube\Data\Database\Database;
use Cube\Data\Database\Query;
use Cube\Data\Models\Model;

class WorkspaceService
{
    public const SECTIONS = ['tasks', 'jira', 'merge_requests', 'events', 'automation', 'webhooks', 'documentation', 'agenda'];

    public const MAX_AGENDA_REMINDER_MINUTES = 10080;

    public const MCP_BLOCKED = 'blocked';
    public const MCP_READ = 'read';
    public const MCP_WRITE = 'write';
    public const MCP_ACCESSES = [self::MCP_BLOCKED, self::MCP_READ, self::MCP_WRITE];

    public function __construct(
        protected Database $database,
        protected IntegrationService $integrations,
        protected WorkspaceIconService $icons,
        protected DocumentationService $documentation,
        protected DocumentationImages $docImages,
        protected AccessKeyService $keys,
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
    public function update(Workspace $workspace, string $name, ?string $accentColor, ?array $sections = null, ?string $mcpAccess = null, int|false|null $agendaReminderMinutes = false): Workspace
    {
        $workspace->mcp_access = $mcpAccess ?? $workspace->mcp_access;
        if (false !== $agendaReminderMinutes)
            $workspace->agenda_reminder_minutes = $agendaReminderMinutes;
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
        return WorkspaceMember::select(['_user'])
            ->where('workspace', $workspace->id)
            ->order('id', 'ASC')
            ->toBunch()
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
     * @return bool false when the user is its last member : a workspace is deleted, never left empty
     */
    public function removeMember(Workspace $workspace, int $userId): bool
    {
        if (WorkspaceMember::select()->where('workspace', $workspace->id)->count() <= 1)
            return false;

        WorkspaceMember::deleteWhere(['workspace' => $workspace->id, 'user' => $userId]);
        // The devices they set up would keep receiving every event of the workspace
        foreach ($this->keys->createdBy($workspace->id, $userId) as $key)
            $this->keys->revoke($key);
        // What they asked to be notified of here is theirs only
        NotificationRule::deleteWhere(['workspace' => $workspace->id, 'user' => $userId]);
        PushSubscription::deleteWhere(['workspace' => $workspace->id, 'user' => $userId]);
        AgendaItemParticipant::delete()
            ->where('user', $userId)
            ->whereIn('item', $this->idsOf(AgendaItem::class, $workspace->id))
            ->fetch();

        return true;
    }

    public function isLastWorkspaceOf(int $userId): bool
    {
        return $this->memberships($userId)->count() <= 1;
    }

    /**
     * Opens its own transaction : Cube cannot nest them, never call it inside one.
     */
    public function delete(Workspace $workspace): void
    {
        $id = $workspace->id;
        $imageFiles = $this->docImages->filesOf($id);
        $failure = $this->database->transaction(function () use ($id) {
            // No foreign key cascades : children go before the rows they reference
            EventDelivery::delete()->whereIn('history', $this->idsOf(History::class, $id))->fetch();
            History::delete()->where('workspace', $id)->fetch();
            SprintLink::delete()->whereIn('sprint', $this->idsOf(Sprint::class, $id))->fetch();
            Task::delete()->where('workspace', $id)->fetch();
            Sprint::delete()->where('workspace', $id)->fetch();
            foreach (Integration::select()->where('workspace', $id)->fetch() as $integration)
                $this->integrations->delete($integration);
            WebhookCall::delete()->whereIn('webhook', $this->idsOf(Webhook::class, $id))->fetch();
            Webhook::delete()->where('workspace', $id)->fetch();
            ConfigDownload::delete()->whereIn('access_key', $this->idsOf(AccessKey::class, $id))->fetch();
            $this->documentation->deleteWorkspacePages($id);
            AgendaItemParticipant::delete()->whereIn('item', $this->idsOf(AgendaItem::class, $id))->fetch();
            AgendaItemException::delete()->whereIn('item', $this->idsOf(AgendaItem::class, $id))->fetch();
            AgendaItem::delete()->where('workspace', $id)->fetch();

            foreach ([AccessKey::class, ApiToken::class, QuickAction::class, ScheduledEvent::class, NotificationRule::class, PushSubscription::class, DocImage::class, WorkspaceMember::class] as $model)
                $model::delete()->where('workspace', $id)->fetch();

            Workspace::delete()->where('id', $id)->fetch();
        });

        if ($failure)
            throw $failure;

        // Once the rows are gone : a failed deletion keeps its files
        $this->icons->deleteFile($workspace);
        $this->docImages->deleteFiles($imageFiles);
    }

    /**
     * @param class-string<Model> $model
     */
    private function idsOf(string $model, int $workspace): Query
    {
        $table = $model::table();

        return Query::select($table)->selectField('id', $table)->where('workspace', $workspace, table: $table);
    }

    /**
     * @return list<string> In the order of SECTIONS ; a workspace never configured has them all
     */
    public static function sectionsOf(Workspace $workspace): array
    {
        return null === $workspace->sections ? self::SECTIONS : json_decode($workspace->sections, true);
    }

    /**
     * @return list<int>
     */
    public function reachableIds(int $userId): array
    {
        // An API token opens the workspace it was created for, never the other ones of its user
        if (null !== HistoryActor::getInstance()->tokenName)
            return [$this->current($userId)->id];

        return $this->listFor($userId)->map(fn (Workspace $workspace) => $workspace->id)->toArray();
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
        return $this->memberships($userId)->toBunch()
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
