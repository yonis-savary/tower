<?php

use Cube\Utils\Path;
use Cube\Web\Helpers\StaticServer;

$frontendBuild = Path::relative('Frontend/dist');

// StaticServer would create the missing directory : without a build, Vite serves the frontend
if (!is_dir($frontendBuild))
    return;

$frontend = new StaticServer($frontendBuild);
$router->addService($frontend);
$frontend->registerFallbackRoute($router);
