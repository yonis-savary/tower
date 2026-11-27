<?php

namespace App\Controllers;

use App\Channels\DevicesChannel;
use App\Middlewares\IsAuthenticated;
use Cube\Data\OpenAPI\Attributes\Endpoint;
use Cube\Data\OpenAPI\Attributes\NoContentResponse;
use Cube\Data\OpenAPI\Attributes\RawResponse;
use Cube\Security\Authentication;
use Cube\Web\Controller;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Http\StatusCode;
use Cube\Web\Router\Route;
use Cube\Web\Router\Router;

use function App\currentWorkspaceId;

class DeviceController extends Controller
{
    public function __construct(
        protected DevicesChannel $devices,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::post('/devices/{uuid}/disconnect', [self::class, 'disconnect']),
        ]);
    }

    #[Endpoint('Disconnect device', 'Closes the connection of a device of the current workspace. The connector stops until it is launched again', 'disconnectDevice')]
    #[NoContentResponse('The device is asked to disconnect, if it is still connected')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function disconnect(Request $request, string $uuid): Response
    {
        $workspace = currentWorkspaceId();
        $this->devices->disconnectDevice($workspace, $uuid, 'disconnected_by_user');

        return Response::noContent();
    }
}
