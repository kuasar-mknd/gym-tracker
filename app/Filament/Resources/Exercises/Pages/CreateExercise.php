<?php

declare(strict_types=1);

namespace App\Filament\Resources\Exercises\Pages;

use App\Filament\Resources\Exercises\ExerciseResource;
use App\Models\Exercise;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateExercise extends CreateRecord
{
    #[\Override]
    protected static string $resource = ExerciseResource::class;

    /**
     * `user_id` est affecte a la main, le reste par assignation en masse.
     *
     * Le champ etait envoye a `create()` alors qu'il n'est pas dans le
     * `$fillable` de `Exercise` : hors production le mode strict faisait lever,
     * en production il etait ignore EN SILENCE et la ligne se retrouvait sans
     * proprietaire — avec une notification de succes (#1352).
     *
     * L'ajouter au `$fillable` aurait ouvert le changement de proprietaire a
     * tous les chemins d'assignation en masse, y compris ceux qui partent d'une
     * requete utilisateur. C'est pourquoi il est ecrit ici, et declare dans
     * `CHAMPS_ASSIGNES_EXPLICITEMENT` sur la ressource.
     *
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        /*
         * Les deux formes sont declarees, pas supposees : `$data` est le
         * tableau deshydrate du formulaire, donc non type. Le champ vient d'un
         * `Select` sur les comptes, il porte un identifiant.
         */
        /** @var int|string $identifiant */
        $identifiant = $data['user_id'];

        /*
         * La liste vient de la ressource, elle n'est pas recopiee ici : c'est
         * la meme que celle que le garde de convention lit pour verifier qu'un
         * champ hors `$fillable` est bien assigne a la main. Deux copies
         * finiraient par diverger.
         */
        /** @var array<string, mixed> $attributs */
        $attributs = Arr::except($data, ExerciseResource::CHAMPS_ASSIGNES_EXPLICITEMENT);

        $enregistrement = new Exercise();
        $enregistrement->user_id = (int) $identifiant;
        $enregistrement->fill($attributs);
        $enregistrement->save();

        return $enregistrement;
    }
}
