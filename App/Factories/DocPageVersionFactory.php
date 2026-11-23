<?php

namespace App\Factories;

use App\Models\DocPageVersion;
use Cube\Data\Models\ModelFactory;
use Faker\Generator;

/**
 * @extends ModelFactory<DocPageVersion>
 */
class DocPageVersionFactory extends ModelFactory
{
    public static function getModelClass(): string
    {
        return DocPageVersion::class;
    }

    protected function definition(Generator $faker): array
    {
        return [
            'title' => $faker->sentence(3),
            'content' => "# {$faker->sentence(3)}\n\n{$faker->paragraph()}",
            'tags' => json_encode($faker->words(2), JSON_THROW_ON_ERROR),
        ];
    }
}
