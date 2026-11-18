<?php

namespace App\Session;

use Cube\Env\Session\Drivers\SessionDriverInterface;

class MemorySession implements SessionDriverInterface
{
    /** @var array<string,mixed> */
    protected array $values = [];

    public function initialize(string $namespace) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function set(string $key, mixed $value)
    {
        $this->values[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }

    public function clear(): void
    {
        $this->values = [];
    }

    public function regenerate() {}
}
