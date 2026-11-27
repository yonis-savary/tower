<?php

namespace App\Domain\Account;

use Cube\Env\Cache;

/**
 * Refuses login attempts after too many failures : on one account from one address, and from one
 * address whatever the account, which slows down guessing across many accounts.
 */
class LoginThrottle
{
    public const WINDOW_SECONDS = 15 * Cache::MINUTE;
    public const MAX_FAILURES_PER_ACCOUNT = 5;
    public const MAX_FAILURES_PER_ADDRESS = 50;

    public function isBlocked(string $email, ?string $address, ?Cache $cache = null): bool
    {
        $cache ??= $this->cache();

        return count($this->failuresOf($this->accountKey($email, $address), $cache)) >= self::MAX_FAILURES_PER_ACCOUNT
            || count($this->failuresOf($this->addressKey($address), $cache)) >= self::MAX_FAILURES_PER_ADDRESS;
    }

    public function failed(string $email, ?string $address, ?Cache $cache = null): void
    {
        $cache ??= $this->cache();

        foreach ([$this->accountKey($email, $address), $this->addressKey($address)] as $key)
            $cache->set($key, [...$this->failuresOf($key, $cache), time()], self::WINDOW_SECONDS);
    }

    public function succeeded(string $email, ?string $address, ?Cache $cache = null): void
    {
        ($cache ?? $this->cache())->delete($this->accountKey($email, $address));
    }

    /**
     * @return list<int>
     */
    private function failuresOf(string $key, Cache $cache): array
    {
        $since = time() - self::WINDOW_SECONDS;

        return array_values(array_filter($cache->get($key, []), fn (int $at) => $at > $since));
    }

    private function accountKey(string $email, ?string $address): string
    {
        return 'account-'.hash('sha256', mb_strtolower(trim($email)).'|'.$address);
    }

    private function addressKey(?string $address): string
    {
        return 'address-'.hash('sha256', (string) $address);
    }

    private function cache(): Cache
    {
        return Cache::getInstance()->child('login-throttle');
    }
}
