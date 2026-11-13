<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Workout;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Une fin laissée vide ne rouvre pas une séance quand le compte en a déjà une
 * autre en cours.
 *
 * `DemarrerUneSeanceAction` refuse de démarrer une séance sous une séance en
 * cours, et le reste de l'application lit « la séance en cours » au singulier.
 * Le panneau, qui vide la fin d'une séance terminée ou en crée une sans fin, en
 * fabriquait une seconde. Rouvrir reste permis quand rien d'autre n'est en
 * cours.
 *
 * Implicite : c'est une fin vide que la règle juge, et Laravel ne passe pas les
 * règles ordinaires sur une valeur vide.
 */
final class AucuneAutreSeanceEnCours implements ValidationRule
{
    /** @phpstan-ignore shipmonk.deadProperty.neverRead (lue par Laravel) */
    public bool $implicit = true;

    /**
     * @param  bool  $deJaEnCours  La séance l'est déjà : la laisser sans fin n'en ouvre aucune.
     */
    public function __construct(
        private readonly mixed $idDuCompte,
        private readonly ?int $idDeLaSeance,
        private readonly bool $deJaEnCours = false,
    ) {
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && $value !== '') {
            return;
        }

        if ($this->deJaEnCours || ! is_numeric($this->idDuCompte)) {
            return;
        }

        $autreEnCours = Workout::query()
            ->where('user_id', (int) $this->idDuCompte)
            ->whereNull('ended_at')
            ->when($this->idDeLaSeance !== null, fn ($requete) => $requete->whereKeyNot($this->idDeLaSeance))
            ->exists();

        if ($autreEnCours) {
            $fail('Ce compte a déjà une séance en cours : termine-la avant d’en laisser une autre sans fin.');
        }
    }
}
