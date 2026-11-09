<?php

namespace App\Requests;

use App\Services\TaskService;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class MoveTaskRequest extends Request
{
    public function getRules(): array
    {
        return [
            'sprint' => Param::integer(nullable: true),
            'status' => Param::string()->inArray(TaskService::STATUSES),
            'ordered' => Param::array(Param::integer())->optional(),
        ];
    }
}
