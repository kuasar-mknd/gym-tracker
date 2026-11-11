<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Exercise;
use App\Services\Diagnostic\ValeurLue;
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
 *
 * Le message nomme le champ comme le validateur le ferait, par son nom de
 * `lang/fr/validation.php` (« exercice ») : un nom ecrit ici en dur
 * contournait la traduction et affichait « exercise id » (#1975).
 *
 * La verification tourne apres les regles, meme quand `integer` a refuse la
 * valeur : un identifiant ne s'y lit donc qu'entier ou en chiffres
 * (`ValeurLue::entier()`), et tout autre est refuse. Converti par `(int)`, un
 * flottant demesure faisait avertir PHP, et la requete repondait 500 (#2071).
 */
trait VerifieLesExercicesDuGabarit
{
    protected function verifierLesExercices(Validator $validator): void
    {
        $exercices = $this->input('exercises');

        if (! is_array($exercices) || $exercices === []) {
            return;
        }

        $identifiants = self::identifiantsLus($exercices);

        if ($identifiants === []) {
            return;
        }

        self::signalerLesExercicesRefuses($validator, $exercices, $this->exercicesAutorises($identifiants));
    }

    /**
     * Les identifiants lisibles des exercices du gabarit, dans leur ordre.
     *
     * @param  array<mixed>  $exercices
     * @return list<int>
     */
    private static function identifiantsLus(array $exercices): array
    {
        $identifiants = [];

        foreach ($exercices as $exercice) {
            $identifiant = self::identifiantLu($exercice);

            if ($identifiant !== null) {
                $identifiants[] = $identifiant;
            }
        }

        return $identifiants;
    }

    /**
     * Parmi ces identifiants, ceux des exercices communs et de ceux de
     * l'utilisateur, en une requete.
     *
     * @param  list<int>  $identifiants
     * @return list<int>
     */
    private function exercicesAutorises(array $identifiants): array
    {
        $idUtilisateur = $this->user()?->id;

        /** @var list<int> $autorises */
        $autorises = Exercise::query()
            ->whereIn('id', array_values(array_unique($identifiants)))
            ->where(fn (Builder $requete): Builder => $requete->whereNull('user_id')->orWhere('user_id', $idUtilisateur))
            ->pluck('id')
            ->map(fn (mixed $id): int => ValeurLue::entier($id) ?? 0)
            ->all();

        return $autorises;
    }

    /**
     * Refuse, a son rang, chaque exercice illisible ou hors des autorises.
     *
     * @param  array<mixed>  $exercices
     * @param  list<int>  $autorises
     */
    private static function signalerLesExercicesRefuses(Validator $validator, array $exercices, array $autorises): void
    {
        foreach ($exercices as $rang => $exercice) {
            $identifiant = self::identifiantLu($exercice);

            if ($identifiant === null || ! in_array($identifiant, $autorises, true)) {
                $cle = "exercises.{$rang}.id";

                $validator->errors()->add(
                    $cle,
                    __('validation.exists', ['attribute' => $validator->getDisplayableAttribute($cle)])
                );
            }
        }
    }

    /**
     * L'identifiant d'un exercice du gabarit, `null` s'il n'est ni un entier
     * ni une chaine de chiffres.
     */
    private static function identifiantLu(mixed $exercice): ?int
    {
        return ValeurLue::entier(is_array($exercice) ? ($exercice['id'] ?? null) : null);
    }
}
