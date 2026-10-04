<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workouts\Pages;

use App\Filament\Resources\Workouts\WorkoutResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditWorkout extends EditRecord
{
    #[\Override]
    protected static string $resource = WorkoutResource::class;

    /**
     * Enregistre la séance modifiée, sans jamais toucher à son propriétaire.
     *
     * La page écrivait `user_id` à la main (#1352) : un exploitant pouvait donc
     * donner la séance à un autre compte, et ses lignes, ses séries, ses
     * records et la série de jours des deux comptes restaient à l'ancien
     * (#1933). Le champ est désormais désactivé à la modification
     * (`WorkoutForm`) : Filament ne le déshydrate plus, il n'arrive pas dans
     * `$data`, même quand une requête Livewire forgée en change la valeur.
     *
     * Le retrait ci-dessous ne le suppose pas pour autant : un champ que la
     * page affecte à la main à la création n'a rien à faire dans une
     * assignation en masse, où le mode strict le ferait lever hors production.
     * La liste vient de la ressource, celle que lit la garde de convention.
     *
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var array<string, mixed> $attributs */
        $attributs = Arr::except($data, WorkoutResource::CHAMPS_ASSIGNES_EXPLICITEMENT);

        $record->fill($attributs);
        $record->save();

        return $record;
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
