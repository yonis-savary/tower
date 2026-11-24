<?php

namespace App\Agenda;

use App\Requests\Rules\Instant;

final class AgendaItemData
{
    /**
     * @param list<int> $participants
     */
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly bool $allDay,
        public readonly ?string $link,
        public readonly ?string $color,
        public readonly array $participants,
    ) {}

    /**
     * @param array<string,mixed> $validated
     */
    public static function fromValidated(array $validated): self
    {
        $link = trim((string) ($validated['link'] ?? ''));

        return new self(
            trim($validated['title']),
            trim((string) ($validated['description'] ?? '')),
            Instant::toServerTime($validated['starts_at']),
            Instant::toServerTime($validated['ends_at']),
            (bool) ($validated['all_day'] ?? false),
            '' === $link ? null : $link,
            null === ($validated['color'] ?? null) ? null : strtolower($validated['color']),
            array_values(array_unique(array_map('intval', $validated['participants'] ?? []))),
        );
    }
}
