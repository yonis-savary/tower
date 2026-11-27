<?php

namespace App\Requests;

use App\Domain\Workspace\WorkspaceService;
use App\Requests\Rules\HexColor;
use Cube\Web\Http\Rules\Param;

class UpdateWorkspaceRequest extends WorkspaceNameRequest
{
    public function getRules(): array
    {
        return [
            ...parent::getRules(),
            'accent_color' => HexColor::param(),
            'sections' => Param::array(Param::string()->inArray(WorkspaceService::SECTIONS))->optional(),
            'mcp_access' => Param::string()->inArray(WorkspaceService::MCP_ACCESSES)->optional(),
            'agenda_reminder_minutes' => Param::integer(nullable: true)->optional()->withValueCondition(
                fn ($minutes) => null === $minutes || (1 <= $minutes && $minutes <= WorkspaceService::MAX_AGENDA_REMINDER_MINUTES),
                '{key} must be a number of minutes between 1 and '.WorkspaceService::MAX_AGENDA_REMINDER_MINUTES.', or null'
            ),
        ];
    }
}
