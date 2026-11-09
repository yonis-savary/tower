<?php

namespace App\Security;

use Cube\Env\Configuration\ConfigurationElement;

class RegistrationConfiguration extends ConfigurationElement
{
    public function __construct(
        public readonly bool $open = true,
    ) {}
}
