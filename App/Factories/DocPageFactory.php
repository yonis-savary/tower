<?php

namespace App\Factories;

use App\Models\DocPage;
use Cube\Data\Models\ModelFactory;
use Faker\Generator;

/**
 * @extends ModelFactory<DocPage>
 */
class DocPageFactory extends ModelFactory
{
    public static function getModelClass(): string
    {
        return DocPage::class;
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
