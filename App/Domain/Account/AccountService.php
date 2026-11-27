<?php

namespace App\Domain\Account;

use App\Models\User;

class AccountService
{
    /**
     * @return bool false when the current password is wrong
     */
    public function changePassword(User $user, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword): bool
    {
        if (!password_verify($currentPassword, $user->password_hash))
            return false;

        $user->password_hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $user->save();

        return true;
    }
}
