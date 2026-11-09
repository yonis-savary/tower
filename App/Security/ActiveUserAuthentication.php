<?php

namespace App\Security;

use Cube\Data\Models\Model;
use Cube\Security\Authentication\PasswordAuthentication;

/**
 * A disabled user keeps their data but can no longer log in.
 */
class ActiveUserAuthentication extends PasswordAuthentication
{
    public function attempt(string $identifier, #[\SensitiveParameter] ?string $password = null): false|Model
    {
        $user = parent::attempt($identifier, $password);

        return $user && !$user->disabled_at ? $user : false;
    }

    public function userById(mixed $id): false|Model
    {
        $user = parent::userById($id);

        return $user && !$user->disabled_at ? $user : false;
    }
}
