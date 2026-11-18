<?php

namespace App\Security;

use App\Session\MemorySession;
use Cube\Env\Session\SessionConfiguration;
use Cube\Security\Authentication;

class ApiTokenAuthentication extends Authentication
{
    protected function getSessionConfiguration(): SessionConfiguration
    {
        return new SessionConfiguration(driver: new MemorySession());
    }
}
