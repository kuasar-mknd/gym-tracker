<?php

declare(strict_types=1);

namespace App\Filament\Resources\Goals\Pages;

use App\Filament\Resources\Goals\GoalResource;
use App\Models\Goal;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

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
     * que la production faisait sans lui, en silence (#2071).
     *
     * @param  array<mixed>  $data
     */
    public static function modifier(Goal $record, array $data): Goal
    {
        /** @var int|string $identifiant */
        $identifiant = $data['user_id'];

        /*
         * La liste vient de la ressource, elle n'est pas recopiee ici : c'est
         * la meme que celle que le garde de convention lit pour verifier qu'un
         * champ hors `$fillable` est bien assigne a la main. Deux copies
         * finiraient par diverger.
         */
        /** @var array<string, mixed> $attributs */
        $attributs = Arr::except($data, GoalResource::CHAMPS_ASSIGNES_EXPLICITEMENT);

        $record->user_id = (int) $identifiant;
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
