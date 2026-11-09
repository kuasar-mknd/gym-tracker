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
     * La page relit ensuite dans son formulaire l'avancement que
     * l'enregistrement vient de recalculer (#1513). `EditRecord::save()` ne
     * recharge pas le formulaire : sans redirection, il garde l'état envoyé.
     * La valeur actuelle et la date d'atteinte, en lecture seule, montraient
     * donc ce qui était chargé à l'ouverture de la page, comme si
     * l'enregistrement n'avait rien recalculé, jusqu'à ce que l'administrateur
     * la recharge. L'objectif de la page est celui que
     * `GoalResource::enregistrer()` a relu après le recalcul. La relecture se
     * fait ici plutôt que dans le crochet `afterSave()`, que Filament appelle
     * par son nom et que le détecteur de code mort tiendrait pour inutilisé.
     * L'action de la table n'en a pas besoin : sa modale se ferme, et la table
     * relit la base.
     *
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Goal $record */
        return tap(self::modifier($record, $data), function (): void {
            $this->refreshFormData(['current_value', 'completed_at']);
        });
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
