<?php

namespace App\Commands;

use App\Domain\Account\UserRegistration;
use Cube\Console\Args;
use Cube\Console\Command;
use Cube\Utils\Console;

/**
 * Creates an account with its first workspace, the only way to get one while registration is
 * closed (REGISTRATION_OPEN). Prints a temporary password, to change from the account settings.
 */
class CreateUser extends Command
{
    public function __construct(
        protected UserRegistration $registration,
    ) {}

    public function getScope(): string
    {
        return 'app';
    }

    public function getHelp(): string
    {
        return 'Create an account and print its temporary password (eg: php do app:create-user alice@example.com)';
    }

    public function execute(Args $args): int
    {
        $email = strtolower(trim((string) $args->getValue()));
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Console::print("Expected an e-mail address, got [{$email}]");

            return 1;
        }

        $password = bin2hex(random_bytes(12));
        if (!$this->registration->register($email, $password)) {
            Console::print("An account already uses {$email}");

            return 1;
        }

        // Printed, never logged : Console::log() would also write it to Storage/Logs
        Console::print("Account created for {$email}", "Temporary password : {$password}", 'Change it from the account settings after the first login');

        return 0;
    }
}
