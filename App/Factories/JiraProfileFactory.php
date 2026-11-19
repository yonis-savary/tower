<?php

namespace App\Factories;

use App\Models\JiraProfile;
use Cube\Data\Models\ModelFactory;
use Faker\Generator;

/**
 * Valid JSON in the columns : the OpenAPI document reads the shape of a profile from one of these.
 *
 * @extends ModelFactory<JiraProfile>
 */
class JiraProfileFactory extends ModelFactory
{
    public static function getModelClass(): string
    {
        return JiraProfile::class;
    }

    protected function definition(Generator $faker): array
    {
        return [
            'name' => 'Billing team',
            'board_name' => 'PLAT board',
            'jql' => 'component = Billing',
            'columns' => json_encode([['name' => 'In Review', 'statuses' => ['10002', '10007']]], JSON_THROW_ON_ERROR),
            'done_statuses' => json_encode(['10007'], JSON_THROW_ON_ERROR),
        ];
    }
}
