<?php

namespace App\Services;

use App\Models\User;

class UserRegistration
{
    public function __construct(
        protected WorkspaceService $workspaces,
    ) {}

    /**
     * Create a user with their default workspace.
     *
     * @return ?User `null` when the email is already taken
     */
    public function register(string $email, #[\SensitiveParameter] string $password): ?User
    {
        if ($this->emailIsTaken($email))
            return null;

        try {
            // Not fromArray() nor a DateTime : generated models type a DATETIME column as DATE, which drops the time
            $user = (new User([
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'created_at' => date('Y-m-d H:i:s'),
            ]))->save();
        } catch (\PDOException $exception) {
            // Two simultaneous registrations : the unique index refused the second one
            if ($this->emailIsTaken($email))
                return null;

            throw $exception;
        }

        $this->workspaces->createDefault($user);

        return $user;
    }

    private function emailIsTaken(string $email): bool
    {
        return User::existsWhere(['email' => $email]);
    }
}
