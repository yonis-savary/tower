<?php

namespace App\Resources;

use App\Models\User;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;

/**
 * @extends ModelResource<User>
 */
class UserResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return User::class;
    }

    /**
     * @param User $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'email' => $model->email,
        ];
    }
}
