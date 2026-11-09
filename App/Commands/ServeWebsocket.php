<?php

namespace App\Commands;

use Cube\Console\Args;
use Cube\Console\Commands\Websocket\Serve;
use Cube\Data\Database\Database;
use Cube\Env\Logger\Logger;
use React\EventLoop\Loop;

/**
 * The websocket server, with a ping that keeps its only database connection open while nothing happens.
 */
class ServeWebsocket extends Serve
{
    private const PING_INTERVAL_SECONDS = 300;

    public function getScope(): string
    {
        return 'app';
    }

    public function getHelp(): string
    {
        return 'Start the websocket server and keep its database connection alive';
    }

    public function execute(Args $args): int
    {
        Loop::addPeriodicTimer(self::PING_INTERVAL_SECONDS, $this->pingDatabase(...));

        return parent::execute($args);
    }

    public function pingDatabase(): void
    {
        try {
            Database::getInstance()->query('SELECT 1');
        } catch (\Throwable $thrown) {
            // The connection cannot come back : the container restarts with a new one, and connectors reconnect
            Logger::getInstance()->logThrowable($thrown);
            exit(1);
        }
    }
}
