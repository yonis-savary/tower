<?php

use App\Domain\Documentation\DocumentationService;

Cube\daily([DocumentationService::class, 'purgeExpiredVersions']);
