<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renumérote les enfants d'une relation (les séries d'une ligne, les lignes
 * d'une séance) dans l'ordre soumis, en une seule écriture : une par enfant
 * rendrait un ordre intermédiaire lisible entre deux écritures.
 *
 * À appeler après l'autorisation : la lecture des enfants faite avant elle
 * coûterait une requête de plus à une ressource d'autrui qu'à une ressource
 * inconnue, et le contrat de non-divulgation compte ces requêtes.
 */
class ReorderAction
{
    /**
     * L'ordre soumis doit être une permutation des enfants : une liste
     * partielle laisserait les absents sur leur rang d'origine, en double
     * avec ceux qu'on vient de renuméroter.
     *
     * @param  HasMany<covariant Model, covariant Model>  $enfants
     * @param  array<mixed>  $ordre
     * @param  string  $champ  le champ de la requête à mettre en erreur
     *
     * @throws ValidationException
     */
    public function execute(HasMany $enfants, array $ordre, string $champ): void
    {
        $identifiants = array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : PHP_INT_MIN, array_values($ordre));
        $attendus = $enfants->pluck('id')->all();

        $soumis = $identifiants;
        sort($soumis);
        sort($attendus);

        if ($soumis !== $attendus) {
            throw ValidationException::withMessages([
                $champ => 'La liste doit reprendre chaque élément existant, une seule fois.',
            ]);
        }

        if ($identifiants === []) {
            return;
        }

        $quand = [];
        $liaisons = [];

        foreach ($identifiants as $rang => $id) {
            $quand[] = 'when ? then ?';
            $liaisons[] = $id;
            $liaisons[] = $rang;
        }

        DB::update(
            'update '.$enfants->getRelated()->getTable().' set `order` = case id '.implode(' ', $quand).' end, updated_at = ?
             where '.$enfants->getForeignKeyName().' = ? and id in ('.implode(',', array_fill(0, count($identifiants), '?')).')',
            [...$liaisons, now(), $enfants->getParentKey(), ...$identifiants]
        );
    }
}
