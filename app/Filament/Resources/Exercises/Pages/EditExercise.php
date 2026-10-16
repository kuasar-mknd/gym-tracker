<?php

declare(strict_types=1);

namespace App\Filament\Resources\Exercises\Pages;

use App\Filament\Resources\Exercises\ExerciseResource;
use App\Models\Exercise;
use App\Services\Diagnostic\ValeurLue;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditExercise extends EditRecord
{
    #[\Override]
    protected static string $resource = ExerciseResource::class;

    /**
     * Meme raison qu'a la creation : `user_id` n'est pas dans le `$fillable`.
     *
     * `EditRecord` appelle `$record->update($data)`, donc l'edition empruntait
     * exactement le meme chemin d'assignation en masse que la creation — et
     * perdait la meme valeur en silence en production (#1352).
     *
     * Un exercice du catalogue n'a pas de compte : le champ vide reste vide
     * (`ValeurLue::entier()`), là où `(int)` en faisait le compte 0, que la
     * clé étrangère refusait, et l'enregistrement finissait en 500 (#2071).
     *
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Exercise $record */
        return self::modifier($record, $data);
    }

    /**
     * L'écriture de la page, que l'action de modification de la table reprend
     * (`ExercisesTable`) : sans elle, l'action enregistrait par
     * `$record->update($data)`, que le mode strict refusait pour `user_id` et
     * que la production faisait sans lui, en silence (#2071).
     *
     * @param  array<mixed>  $data
     */
    public static function modifier(Exercise $record, array $data): Exercise
    {
        /** @var int|string|null $identifiant */
        $identifiant = $data['user_id'];

        /*
         * La liste vient de la ressource, elle n'est pas recopiee ici : c'est
         * la meme que celle que le garde de convention lit pour verifier qu'un
         * champ hors `$fillable` est bien assigne a la main. Deux copies
         * finiraient par diverger.
         */
        /** @var array<string, mixed> $attributs */
        $attributs = Arr::except($data, ExerciseResource::CHAMPS_ASSIGNES_EXPLICITEMENT);

        $record->user_id = ValeurLue::entier($identifiant);
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
