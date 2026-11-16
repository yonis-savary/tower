<?php

namespace App\Commands;

use Cube\Console\Args;
use Cube\Console\Command;
use Cube\Utils\Console;
use Minishlink\WebPush\VAPID;

/**
 * Prints a new pair of VAPID keys to copy into `.env`. Generate them once : new keys invalidate
 * every browser subscription.
 */
class PushKeys extends Command
{
    public function getScope(): string
    {
        return 'app';
    }

    public function getHelp(): string
    {
        return 'Print a new pair of VAPID keys for the push notifications';
    }

    public function execute(Args $args): int
    {
        $keys = VAPID::createVapidKeys();

        Console::log('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        Console::log('VAPID_PRIVATE_KEY='.$keys['privateKey']);

        return 0;
    }
}
