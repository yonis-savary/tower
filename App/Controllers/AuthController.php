<?php

namespace App\Controllers;

use App\Middlewares\IsAuthenticated;
use App\Requests\LoginRequest;
use App\Requests\RegisterRequest;
use App\Resources\UserResource;
use App\Security\RegistrationConfiguration;
use App\Services\UserRegistration;
use Cube\Data\OpenAPI\Attributes\Endpoint;
use Cube\Data\OpenAPI\Attributes\NoContentResponse;
use Cube\Data\OpenAPI\Attributes\RawResponse;
use Cube\Data\OpenAPI\Attributes\ResourceResponse;
use Cube\Security\Authentication;
use Cube\Web\Controller;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Http\StatusCode;
use Cube\Web\Router\Route;
use Cube\Web\Router\Router;

class AuthController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected UserRegistration $registration,
        protected RegistrationConfiguration $registrationConfiguration,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api/auth', routes: [
            Route::post('/register', [self::class, 'register']),
            Route::post('/login', [self::class, 'login']),
        ]);

        $router->group('/api/auth', middlewares: [IsAuthenticated::class], routes: [
            Route::post('/logout', [self::class, 'logout']),
            Route::get('/me', [self::class, 'currentUser']),
        ]);
    }

    #[Endpoint('Register', 'Creates an account with its first workspace, then opens a session', 'register')]
    #[ResourceResponse(UserResource::class, responseCode: StatusCode::CREATED, description: 'The created user, now logged in')]
    #[RawResponse(['error' => 'Registration is closed'], responseCode: StatusCode::FORBIDDEN, description: 'Registration is closed on this instance')]
    #[RawResponse(['error' => 'Email already used'], responseCode: StatusCode::CONFLICT, description: 'An account already uses this email')]
    public function register(RegisterRequest $request): Response
    {
        if (!$this->registrationConfiguration->open)
            return Response::json(['error' => 'Registration is closed'], StatusCode::FORBIDDEN);

        $user = $this->registration->register($request->validated('email'), $request->validated('password'));
        if (!$user)
            return Response::json(['error' => 'Email already used'], StatusCode::CONFLICT);

        $this->authentication->login($user);

        return Response::json(UserResource::of($user), StatusCode::CREATED);
    }

    #[Endpoint('Log in', 'Opens a session from an email and a password', 'login')]
    #[ResourceResponse(UserResource::class, description: 'The logged in user')]
    #[RawResponse(['error' => 'Invalid credentials'], responseCode: StatusCode::UNAUTHORIZED, description: 'Wrong email or password, or disabled account')]
    public function login(LoginRequest $request): Response
    {
        if (!$this->authentication->attempt($request->validated('email'), $request->validated('password')))
            return Response::json(['error' => 'Invalid credentials'], StatusCode::UNAUTHORIZED);

        return Response::json(UserResource::of($this->authentication->user()));
    }

    #[Endpoint('Log out', 'Closes the current session', 'logout')]
    #[NoContentResponse('The session is closed')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function logout(Request $request): Response
    {
        $this->authentication->logout();

        return Response::noContent();
    }

    #[Endpoint('Current user', 'The user of the current session', 'getCurrentUser')]
    #[ResourceResponse(UserResource::class, description: 'The logged in user')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function currentUser(Request $request): Response
    {
        return Response::json(UserResource::of($this->authentication->user()));
    }
}
