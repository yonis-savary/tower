<?php

namespace App\Requests;

use App\Domain\AccessKey\Connector\ConfigDownloadService;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class CreateDownloadLinkRequest extends Request
{
    public function getRules(): array
    {
        return [
            'secret' => Param::string(trim: false),
            'format' => Param::string()->inArray(ConfigDownloadService::FORMATS),
        ];
    }
}
