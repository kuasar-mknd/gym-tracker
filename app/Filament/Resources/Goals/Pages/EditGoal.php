<?php

declare(strict_types=1);

namespace App\Filament\Resources\Goals\Pages;

use App\Filament\Resources\Goals\GoalResource;
use App\Models\Goal;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditGoal extends EditRecord
{
    #[\Override]
    protected static string $resource = GoalResource::class;

    /**
     * Meme raison qu'a la creation : `user_id` n'est pas dans le `$fillable`.
     *
     * `EditRecord` appelle `$record->update($data)`, donc l'edition empruntait
     * exactement le meme chemin d'assignation en masse que la creation — et
     * perdait la meme valeur en silence en production (#1352).
     *
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Goal $record */
        return self::modifier($record, $data);
    }

    /**
     * L'écriture de la page, que l'action de modification de la table reprend
     * (`GoalsTable`) : sans elle, l'action enregistrait par
     * `$record->update($data)`, que le mode strict refusait pour `user_id` et
     * que la production faisait sans lui, en silence (#2071). Elle passe par
     * `GoalResource::enregistrer()`, qui recalcule l'avancement de l'objectif
     * par son écrivain (#1513).
     *
     * @param  array<mixed>  $data
     */
    public static function modifier(Goal $record, array $data): Goal
    {
        return GoalResource::enregistrer($record, $data);
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
