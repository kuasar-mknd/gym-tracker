<?php

declare(strict_types=1);

namespace App\Filament\Resources\Goals\Pages;

use App\Filament\Resources\Goals\GoalResource;
use App\Models\Goal;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGoal extends CreateRecord
{
    #[\Override]
    protected static string $resource = GoalResource::class;

    /**
     * `user_id` est affecte a la main, le reste par assignation en masse.
     *
     * Le champ etait envoye a `create()` alors qu'il n'est pas dans le
     * `$fillable` de `Goal` : hors production le mode strict faisait lever,
     * en production il etait ignore EN SILENCE et la ligne se retrouvait sans
     * proprietaire — avec une notification de succes (#1352).
     *
     * L'ajouter au `$fillable` aurait ouvert le changement de proprietaire a
     * tous les chemins d'assignation en masse, y compris ceux qui partent d'une
     * requete utilisateur. C'est pourquoi il est ecrit a la main, et declare
     * dans `CHAMPS_ASSIGNES_EXPLICITEMENT` sur la ressource.
     *
     * L'écriture est celle de la page de modification et de l'action de la
     * table (`GoalResource::enregistrer()`) : l'avancement de l'objectif s'y
     * recalcule par son écrivain, il ne se tape pas (#1513).
     *
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        return GoalResource::enregistrer(new Goal(), $data);
    }
}
