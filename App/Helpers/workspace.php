<?php

namespace App;

use App\Models\Workspace;
use App\Services\WorkspaceService;
use Cube\Security\Authentication;

function currentWorkspace(?Authentication $authentication = null): Workspace
{
    $authentication ??= Authentication::getInstance();

    return inject(WorkspaceService::class)->current($authentication->userId());
}

function currentWorkspaceId(?Authentication $authentication = null): int
{
    return currentWorkspace($authentication)->id;
}
