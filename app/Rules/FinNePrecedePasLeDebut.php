<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Workout;
use App\Support\HeureDuChampDeSeance;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * La fin d'une séance du panneau ne précède pas son début, jugée sur les
 * instants que les deux champs désigneraient une fois enregistrés, décalage
 * compris : une séance de 02:50 CEST à 02:10 CET dure vingt minutes, et sa fin
 * murale précède pourtant son début.
 *
 * Une heure qui n'existe pas (`HeureQuiExiste`) ne se juge pas ici : la
 * comparaison manque, et c'est au champ concerné de la refuser. `$debutSaisi` rend le texte saisi tel quel : l'état que
 * Filament en tire corrige `2026-02-30` en `2026-03-02` et lève sur un texte.
 */
final readonly class FinNePrecedePasLeDebut implements ValidationRule
{
    public function __construct(private Closure $debutSaisi, private ?Workout $seance)
    {
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $fin = HeureDuChampDeSeance::instant($value, $this->seance?->ended_at);
        $debut = HeureDuChampDeSeance::instant(($this->debutSaisi)(), $this->seance?->started_at);

        if ($fin instanceof CarbonInterface && $debut?->greaterThan($fin) === true) {
            $fail('La fin doit être postérieure ou égale au début.');
        }
    }
}
