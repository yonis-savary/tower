<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Cube\Data\Database\Database;

class UserRegistration
{
    public function __construct(
        protected Database $database,
    ) {}

    /**
     * Create a user with their first workspace.
     *
     * @return ?User `null` when the email is already taken
     */
    public function register(string $email, #[\SensitiveParameter] string $password): ?User
    {
        if ($this->emailIsTaken($email))
            return null;

        $user = null;
        $failure = $this->database->transaction(function () use ($email, $password, &$user) {
            // Not fromArray() nor a DateTime : generated models type a DATETIME column as DATE, which drops the time
            $user = (new User([
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'created_at' => date('Y-m-d H:i:s'),
            ]))->save();

            $workspace = Workspace::fromArray([
                'name' => 'Mon workspace',
                'author' => $user->id,
            ])->save();

            WorkspaceMember::fromArray([
                'workspace' => $workspace->id,
                'user' => $user->id,
            ])->save();
        });

        if (!$failure)
            return $user;

        // Two simultaneous registrations : the unique index refused the second one
        if ($this->emailIsTaken($email))
            return null;

        throw $failure;
    }

    private function emailIsTaken(string $email): bool
    {
        return null !== User::findWhere(['email' => $email]);
    }
}
