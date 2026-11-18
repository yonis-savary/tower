<?php

namespace App\Favicons;

class Favicon
{
    public function __construct(
        public readonly string $type,
        public readonly string $body,
    ) {}
}
