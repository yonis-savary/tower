<?php

namespace App\Resources;

use App\Agenda\Recurrence;
use App\Models\AgendaItem;
use App\Models\AgendaItemParticipant;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<AgendaItem>
 */
#[Nullable(['link', 'color', 'recurrence', 'recurrence.until', 'occurrence'])]
class AgendaItemResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return AgendaItem::class;
    }

    /**
     * @param AgendaItem $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'workspace' => $model->workspace,
            'title' => $model->title,
            'description' => $model->description,
            'starts_at' => AtomDate::format($model->starts_at),
            'ends_at' => AtomDate::format($model->ends_at),
            'all_day' => (bool) $model->all_day,
            'link' => $model->link,
            'color' => $model->color,
            'recurrence' => Recurrence::of($model)?->toArray(),
            // A recurring item is listed once per occurrence, with its dates : this day (server time) names it in a change
            'occurrence' => $model->isRecurring() ? substr(AtomDate::format($model->starts_at), 0, 10) : null,
            'participants' => UserResource::of(array_map(fn (AgendaItemParticipant $participant) => $participant->_user, $model->participants)),
            'created_by' => $model->created_by,
        ];
    }
}
