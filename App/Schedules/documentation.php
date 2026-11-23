<?php

use App\Documentation\DocumentationService;

Cube\daily([DocumentationService::class, 'purgeExpiredVersions']);
