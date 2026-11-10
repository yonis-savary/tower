<?php

use App\Models\User;
use App\Security\ActiveUserAuthentication;
use App\Security\RegistrationConfiguration;
use Cube\Core\Autoloader\Applications;
use Cube\Core\Autoloader\AutoloaderConfiguration;
use Cube\Data\Database\DatabaseConfiguration;
use Cube\Data\OpenAPI\OpenAPIConfiguration;
use Cube\Env\Session\Drivers\DatabaseSession;
use Cube\Env\Session\SessionConfiguration;
use Cube\Security\Authentication\AuthenticationConfiguration;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;
use Cube\Web\Websocket\Servers\WebsocketConfiguration;

use function Cube\env;

// The address devices and browsers open the websocket on : the public one behind a reverse proxy,
// the published port of the websocket container otherwise
$websocketUrl = parse_url(env('TOWER_WEBSOCKET_URL') ?: 'ws://localhost:' . env('TOWER_WEBSOCKET_PORT', '8088'));
if (!in_array($websocketUrl['scheme'] ?? null, ['ws', 'wss'], true) || !isset($websocketUrl['host']) || isset($websocketUrl['path']) && '/' !== $websocketUrl['path'])
    throw new InvalidArgumentException('TOWER_WEBSOCKET_URL must look like wss://tower.example.com, without a path');

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
        allowedOrigins: explode(',', env('WEBSOCKET_ALLOWED_ORIGINS', 'http://localhost:' . env('TOWER_VITE_DEV_PORT', '5173') . ',http://localhost:' . env('TOWER_HTTP_PORT', '8081'))),
    ),

    new BroadcastConfiguration(
        socketHost: $websocketUrl['scheme'] . '://' . $websocketUrl['host'],
        socketPort: $websocketUrl['port'] ?? ('wss' === $websocketUrl['scheme'] ? 443 : 80),
        httpHost: 'websocket',
    ),

    // Class discovery is cached in production only : force it on or off here
    // new AutoloaderConfiguration(cached: true),

    new SessionConfiguration(driver: new DatabaseSession()),

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
