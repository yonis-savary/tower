<?php

namespace App\Requests;

trait ValidatesOnce
{
    /** @var ?array<string,mixed> */
    private ?array $validatedValues = null;

    /**
     * @return array<string,mixed>
     */
    protected function validatedValues(): array
    {
        return $this->validatedValues ??= $this->validated();
    }
}
