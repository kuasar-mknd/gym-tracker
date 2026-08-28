<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Exercise;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\Validator;

/**
 * Verifie en UNE requete que les exercices d'un gabarit existent et appartiennent
 * bien a l'utilisateur.
 *
 * La regle `Rule::exists` posee sur `exercises.*.id` en emettait une PAR element :
 * `DatabasePresenceVerifier::getCount()` construit une requete neuve a chaque
 * appel, sans memoisation, et le chemin groupe n'est jamais emprunte quand le
 * joker eclate le tableau en attributs scalaires.
 */
trait VerifieLesExercicesDuGabarit
{
    protected function verifierLesExercices(Validator $validator): void
    {
        $exercices = $this->input('exercises');

        if (! is_array($exercices) || $exercices === []) {
            return;
        }

        $identifiants = [];

        foreach ($exercices as $exercice) {
            $identifiant = is_array($exercice) ? ($exercice['id'] ?? null) : null;

            if (is_numeric($identifiant)) {
                $identifiants[] = (int) $identifiant;
            }
        }

        if ($identifiants === []) {
            return;
        }

        $userId = $this->user()?->id;

        /** @var list<int> $autorises */
        $autorises = Exercise::query()
            ->whereIn('id', array_values(array_unique($identifiants)))
            ->where(fn (Builder $requete): Builder => $requete->whereNull('user_id')->orWhere('user_id', $userId))
            ->pluck('id')
            ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        foreach ($exercices as $rang => $exercice) {
            $identifiant = is_array($exercice) ? ($exercice['id'] ?? null) : null;

            if (! is_numeric($identifiant) || ! in_array((int) $identifiant, $autorises, true)) {
                $validator->errors()->add(
                    "exercises.{$rang}.id",
                    __('validation.exists', ['attribute' => 'exercise id'])
                );
            }
        }
    }
}
