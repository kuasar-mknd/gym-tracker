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
 * cours, et une séance déjà en cours reste modifiable. Le compte est celui de
 * la séance, ou celui que `$compteSaisi` rend à la création.
 *
 * Implicite : c'est une fin vide que la règle juge, et Laravel ne passe pas les
 * règles ordinaires sur une valeur vide. La vérification précède l'écriture
 * sans prendre le verrou du compte de `DemarrerUneSeanceAction` : elle réduit
 * la fenêtre, elle ne la ferme pas.
 */
final class AucuneAutreSeanceEnCours implements ValidationRule
{
    /** @phpstan-ignore shipmonk.deadProperty.neverRead (lue par Laravel) */
    public bool $implicit = true;

    /**
     * @param  Closure(): mixed  $compteSaisi
     */
    public function __construct(private readonly Closure $compteSaisi, private readonly ?Workout $seance)
    {
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $dejaEnCours = $this->seance instanceof Workout && $this->seance->ended_at === null;

        if (filled($value) || $dejaEnCours) {
            return;
        }

        $autre = Workout::query()
            ->where('user_id', ($this->compteSaisi)())
            ->whereNull('ended_at')
            ->when($this->seance, fn ($requete, Workout $seance) => $requete->whereKeyNot($seance->id))
            ->exists();

        if ($autre) {
            $fail('Ce compte a déjà une séance en cours : termine-la avant d’en laisser une autre sans fin.');
        }
    }
}
