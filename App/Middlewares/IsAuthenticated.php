<?php

namespace App\Middlewares;

use Cube\Security\Authentication;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Http\StatusCode;
use Cube\Web\Middleware;

class IsAuthenticated implements Middleware
{
    public static function handle(Request $request, \Closure $next): Request|Response
    {
        if (!Authentication::getInstance()->isLogged())
            return Response::json(['error' => 'Authentication required'], StatusCode::UNAUTHORIZED);

        return $next($request);
    }
}
