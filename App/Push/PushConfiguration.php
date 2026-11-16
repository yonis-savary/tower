<?php

namespace App\Push;

use Cube\Env\Configuration\ConfigurationElement;

/**
 * The VAPID keys Tower signs its push notifications with. Changing them invalidates every subscription.
 */
class PushConfiguration extends ConfigurationElement
{
    public function __construct(
        public readonly ?string $publicKey = null,
        public readonly ?string $privateKey = null,
        // A `mailto:` or an `https:` address of the administrator, required by some push services
        public readonly ?string $subject = null,
    ) {}

    public function isEnabled(): bool
    {
        return '' !== (string) $this->publicKey && '' !== (string) $this->privateKey && '' !== (string) $this->subject;
    }
}
