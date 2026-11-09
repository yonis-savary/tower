<?php

use Cube\Core\Autoloader\Applications;
use Cube\Core\Autoloader\AutoloaderConfiguration;
use Cube\Data\Database\DatabaseConfiguration;
use Cube\Web\Helpers\StaticServer;
use Cube\Web\Router\RouterConfiguration;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;
use Cube\Web\Websocket\Servers\WebsocketConfiguration;

use function Cube\env;

return [
    // The list of your applications to load
    new Applications('App'),

    new DatabaseConfiguration(
        driver: 'mysql',
        database: env('DB_NAME'),
        host: env('DB_HOST'),
        port: (int) env('DB_PORT', 3306),
        user: env('DB_USER'),
        password: env('DB_PASSWORD'),
    ),

    new WebsocketConfiguration(
        httpHost: '0.0.0.0',
        broadcastSecret: env('WEBSOCKET_SECRET'),
    ),

    new BroadcastConfiguration(
        httpHost: 'websocket',
    ),

    // Class discovery is cached in production only : force it on or off here
    // new AutoloaderConfiguration(cached: true),

    // You can serve your frontend application with a static file server
    // new RouterConfiguration(
    //     apis: [
    //         new StaticServer('FrontEnd/Public')
    //     ]
    // )
];
