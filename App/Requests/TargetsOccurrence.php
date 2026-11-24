<?php

namespace App\Requests;

use App\Agenda\AgendaService;
use Cube\Web\Http\Rules\Param;

/**
 * A change of a recurring agenda item may concern one of its occurrences, the ones after it, or all of them.
 */
trait TargetsOccurrence
{
    /**
     * @return array<string,Param>
     */
    protected function occurrenceRules(): array
    {
        return [
            // The `occurrence` of a listed item : the day it starts on, in server time
            'occurrence' => Param::date(nullable: true)->optional(),
            'scope' => Param::string(nullable: true)->optional()->inArray(AgendaService::SCOPES),
        ];
    }

    public function occurrence(): ?string
    {
        return $this->validated('occurrence');
    }

    // An occurrence alone by default : the whole item without one
    public function scope(): string
    {
        return $this->validated('scope') ?? (null === $this->occurrence() ? AgendaService::SCOPE_SERIES : AgendaService::SCOPE_OCCURRENCE);
    }
}
