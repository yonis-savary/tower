<?php

namespace App\Middlewares;

use App\Models\User;
use Cube\Data\OpenAPI\Attributes\RawResponse;
use Cube\Security\Authentication;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Http\StatusCode;
use Cube\Web\Middleware;

#[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
class IsAuthenticated implements Middleware
{
    public static function handle(Request $request, \Closure $next): Request|Response
    {
        $authentication = Authentication::getInstance();
        if (!$authentication->isLogged())
            return Response::json(['error' => 'Authentication required'], StatusCode::UNAUTHORIZED);

        // The session keeps the user of its login : a user disabled since is only known to the database
        if (null !== User::find($authentication->userId())?->disabled_at) {
            $authentication->logout();

            return Response::json(['error' => 'Authentication required'], StatusCode::UNAUTHORIZED);
        }

        return $next($request);
    }
}
