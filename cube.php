<?php

use App\Models\User;
use App\Security\ActiveUserAuthentication;
use App\Security\RegistrationConfiguration;
use Cube\Core\Autoloader\Applications;
use Cube\Core\Autoloader\AutoloaderConfiguration;
use Cube\Data\Database\DatabaseConfiguration;
use Cube\Data\OpenAPI\OpenAPIConfiguration;
use Cube\Security\Authentication\AuthenticationConfiguration;
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

    new AuthenticationConfiguration(
        new ActiveUserAuthentication(
            model: User::class,
            loginFields: 'email',
            passwordField: 'password_hash',
        )
    ),

    // Close public registration here
    new RegistrationConfiguration(open: true),

    new OpenAPIConfiguration(
        outputFile: 'Frontend/openapi.json',
        title: 'Tower',
    ),
];
