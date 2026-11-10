<?php

namespace App\Requests;

use App\Services\WorkspaceService;
use Cube\Web\Http\Rules\Param;

class UpdateWorkspaceRequest extends WorkspaceNameRequest
{
    public function getRules(): array
    {
        return [
            ...parent::getRules(),
            'accent_color' => Param::string(nullable: true)->withValueCondition(
                fn ($color) => null === $color || 1 === preg_match('/^#[0-9a-fA-F]{6}$/', (string) $color),
                '{key} must be a color like #3b82f6, or null'
            ),
            'sections' => Param::array(Param::string()->inArray(WorkspaceService::SECTIONS))->optional(),
        ];
    }
}
