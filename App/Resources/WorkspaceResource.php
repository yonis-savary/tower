<?php

namespace App\Resources;

use App\Domain\Workspace\WorkspaceIconService;
use App\Domain\Workspace\WorkspaceService;
use App\Models\Workspace;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<Workspace>
 */
#[Nullable(['accent_color', 'icon', 'agenda_reminder_minutes'])]
class WorkspaceResource extends ModelResource
{
    public function __construct(
        protected WorkspaceIconService $icons,
    ) {}

    public static function getModelClass(): string
    {
        return Workspace::class;
    }

    /**
     * @param Workspace $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'accent_color' => $model->accent_color,
            'sections' => WorkspaceService::sectionsOf($model),
            'mcp_access' => $model->mcp_access,
            'agenda_reminder_minutes' => $model->agenda_reminder_minutes,
            'author' => $model->author,
            // The uploaded image, null when there is none
            'icon' => null === $model->icon ? null : $this->iconUrl($model),
            // Always answers : the uploaded image, else the logo of Tower in the workspace color
            'favicon_url' => $this->iconUrl($model),
        ];
    }

    private function iconUrl(Workspace $workspace): string
    {
        return "/api/workspaces/{$workspace->id}/icon?v=".$this->icons->version($workspace);
    }
}
