<?php

namespace App\Factories;

use App\Models\Workspace;
use App\Services\WorkspaceService;
use Cube\Data\Models\ModelFactory;
use Faker\Generator;

/**
 * @extends ModelFactory<Workspace>
 */
class WorkspaceFactory extends ModelFactory
{
    public static function getModelClass(): string
    {
        return Workspace::class;
    }

    protected function definition(Generator $faker): array
    {
        return [
            'sections' => json_encode($faker->randomElements(WorkspaceService::SECTIONS, null), JSON_THROW_ON_ERROR),
            'accent_color' => $faker->hexColor(),
        ];
    }
}
