<?php

namespace App\Resources;

/**
 * Dates are stored without timezone : the browser would read them in its own without this.
 */
final class AtomDate
{
    public static function format(\DateTimeInterface|string|null $date): ?string
    {
        if (null === $date)
            return null;

        return ($date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable($date))->format(\DateTimeInterface::ATOM);
    }
}
