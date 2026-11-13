<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Workout;
use App\Support\HeureDuChampDeSeance;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * La fin d'une séance du panneau ne précède pas son début, jugée sur les
 * instants que les deux champs désigneraient une fois enregistrés, décalage
 * compris : une séance de 02:50 CEST à 02:10 CET dure vingt minutes, et sa fin
 * murale précède pourtant son début.
 *
 * Le début se lit par `$debutSaisi`, qui lève sur une date illisible : la
 * règle du début la refuse déjà, celle-ci ne juge alors rien.
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
        try {
            $debut = HeureDuChampDeSeance::instant(($this->debutSaisi)(), $this->seance?->started_at);
        } catch (\Throwable) {
            return;
        }

        $fin = HeureDuChampDeSeance::instant($value, $this->seance?->ended_at);

        if ($debut?->greaterThan($fin ?? $debut) === true) {
            $fail('La fin doit être postérieure ou égale au début.');
        }
    }
}
