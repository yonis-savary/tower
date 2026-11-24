<?php

namespace App\Requests;

class ListAgendaItemsRequest extends AgendaRangeRequest
{
    protected function fieldRules(): array
    {
        return [
            ...parent::fieldRules(),
            'participants' => self::idList('user'),
        ];
    }

    /**
     * @return ?list<int>
     */
    public function participants(): ?array
    {
        return $this->ids('participants');
    }
}
