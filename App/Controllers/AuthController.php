<?php

namespace App\Controllers;

use App\Domain\Account\AccountService;
use App\Domain\Account\LoginHistory;
use App\Domain\Account\UserRegistration;
use App\Domain\Workspace\WorkspaceService;
use App\Middlewares\IsAuthenticated;
use App\Models\User;
use App\Requests\ChangePasswordRequest;
use App\Requests\LoginRequest;
use App\Requests\RegisterRequest;
use App\Resources\UserResource;
use App\Security\RegistrationConfiguration;
use App\Session\SelectedWorkspace;
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
        protected WorkspaceService $workspaces,
        protected AccountService $accounts,
        protected RegistrationConfiguration $registrationConfiguration,
        protected LoginHistory $logins,
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
            Route::put('/password', [self::class, 'changePassword']),
        ]);
    }

    #[Endpoint('Register', 'Creates an account with its first workspace, then opens a session', 'register')]
    #[ResourceResponse(UserResource::class, responseCode: StatusCode::CREATED, description: 'The created user, now logged in')]
    #[RawResponse(['error' => 'Registration is closed'], responseCode: StatusCode::FORBIDDEN, description: 'Registration is closed on this instance')]
    #[RawResponse(['error' => 'Email already used'], responseCode: StatusCode::CONFLICT, description: 'An account already uses this email')]
    public function register(RegisterRequest $request): Response
    {
        $validated = $request->validated();
        if (!$this->registrationConfiguration->open)
            return Response::json(['error' => 'Registration is closed'], StatusCode::FORBIDDEN);

        $user = $this->registration->register($validated['email'], $validated['password']);
        if (!$user)
            return Response::json(['error' => 'Email already used'], StatusCode::CONFLICT);

        $this->authentication->login($user);
        $this->workspaces->selectFirst($user->id);

        return Response::json(UserResource::of($user), StatusCode::CREATED);
    }

    #[Endpoint('Log in', 'Opens a session from an email and a password', 'login')]
    #[ResourceResponse(UserResource::class, description: 'The logged in user')]
    #[RawResponse(['error' => 'Invalid credentials'], responseCode: StatusCode::UNAUTHORIZED, description: 'Wrong email or password, or disabled account')]
    public function login(LoginRequest $request): Response
    {
        $validated = $request->validated();
        if (!$this->authentication->attempt($validated['email'], $validated['password'])) {
            $this->logins->failed($validated['email']);
            return Response::json(['error' => 'Invalid credentials'], StatusCode::UNAUTHORIZED);
        }

        $user = $this->authentication->user();
        $this->workspaces->createDefault($user);
        $this->workspaces->selectFirst($user->id);
        $this->logins->loggedIn($user);

        return Response::json(UserResource::of($user));
    }

    #[Endpoint('Log out', 'Closes the current session', 'logout')]
    #[NoContentResponse('The session is closed')]
    public function logout(Request $request): Response
    {
        $this->authentication->logout();
        SelectedWorkspace::unset();

        return Response::noContent();
    }

    #[Endpoint('Current user', 'The user of the current session', 'getCurrentUser')]
    #[ResourceResponse(UserResource::class, description: 'The logged in user')]
    public function currentUser(Request $request): Response
    {
        return Response::json(UserResource::of($this->authentication->user()));
    }

    #[Endpoint('Change password', 'Replaces the password of the current user, after checking the current one. Other open sessions stay open', 'changePassword')]
    #[NoContentResponse('The password is changed')]
    #[RawResponse(['error' => 'Wrong current password'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'The current password is wrong, or the new one is invalid')]
    public function changePassword(ChangePasswordRequest $request): Response
    {
        $validated = $request->validated();
        $changed = $this->accounts->changePassword(
            User::find($this->authentication->userId()),
            $validated['current_password'],
            $validated['new_password'],
        );

        return $changed
            ? Response::noContent()
            : Response::json(['error' => 'Wrong current password'], StatusCode::UNPROCESSABLE_CONTENT);
    }
}
