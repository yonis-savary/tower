<?php

namespace App\Resources;

use App\Models\PullRequestApprovals;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;

/**
 * @extends ModelResource<PullRequestApprovals>
 */
class PullRequestApprovalResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return PullRequestApprovals::class;
    }

    /**
     * @param PullRequestApprovals $model
     */
    public function toArray(Model $model): array
    {
        return [
            'pull_request' => (int) $model->pull_request,
            'display_name' => $model->display_name,
            'status' => $model->status,
        ];
    }
}
