<?php

use Cube\Utils\Path;
use App\Web\FrontendServer;

$frontendBuild = Path::relative('Frontend/dist');

// The server would create the missing directory : without a build, Vite serves the frontend
if (!is_dir($frontendBuild))
    return;

$frontend = new FrontendServer($frontendBuild);
$router->addService($frontend);
$frontend->registerFallbackRoute($router);
