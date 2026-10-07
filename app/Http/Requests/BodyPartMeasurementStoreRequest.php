<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\GoalType;
use App\Http\Requests\Concerns\RameneLesDatesAuFuseauDeLApplication;
use App\Models\BodyPartMeasurement;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class BodyPartMeasurementStoreRequest extends FormRequest
{
    use RameneLesDatesAuFuseauDeLApplication;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Une date envoyée avec un décalage désigne le jour de Paris de cet instant,
     * et c'est ce jour que `before_or_equal:today` doit juger (#1952).
     *
     * Une partie proposée saisie sous son nom français est rangée sous sa
     * clef, avec les mesures déjà prises et les objectifs qui la suivent.
     *
     * Sauf quand le compte mesure déjà une partie sous ce nom exact : avant
     * les noms français, un francophone a pu saisir « Taille » à la main. Le
     * nom saisi est gardé quand le compte ne suit pas encore la clef, ou
     * quand la mesure vient de la page de cette partie, qui le demande par
     * `keep_part_name` : la ramener à la clef la ferait manquer à la page où
     * on vient de l'ajouter (#1974). Le compte suit la clef dès qu'il a une
     * mesure rangée sous elle ou un objectif de mensuration qui la lit : la
     * pastille va alors à la clef, sans quoi l'objectif, qui s'affiche sous le
     * même nom français, n'avancerait plus. La comparaison suit la collation
     * de la colonne, comme le regroupement de la page Mensurations.
     * `keep_part_name` n'est qu'une consigne de rangement : la validation ne
     * le rend pas, il n'entre pas en base.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->ramenerLesJoursAuFuseauDeLApplication(['measured_at']);

        $partie = $this->input('part');

        if (! is_string($partie)) {
            return;
        }

        $clef = BodyPartMeasurement::clefDePartie($partie);

        if ($clef === $partie) {
            return;
        }

        if ($this->leCompteMesureDejaLaPartie($partie)
            && ($this->boolean('keep_part_name') || ! $this->leCompteSuitLaClef($clef))) {
            return;
        }

        $this->merge(['part' => $clef]);
    }

    /**
     * Le compte a-t-il déjà une mesure rangée sous ce nom ?
     */
    private function leCompteMesureDejaLaPartie(string $partie): bool
    {
        $utilisateur = $this->user();

        return $utilisateur instanceof User
            && $utilisateur->bodyPartMeasurements()->where('part', $partie)->exists();
    }

    /**
     * Le compte suit-il déjà la partie proposée sous sa clef, par une mesure
     * rangée sous elle ou par un objectif de mensuration qui la lit
     * (`GoalService::releverLaPartieDuCorps()`) ?
     */
    private function leCompteSuitLaClef(string $clef): bool
    {
        $utilisateur = $this->user();

        if (! $utilisateur instanceof User) {
            return false;
        }

        return $this->leCompteMesureDejaLaPartie($clef)
            || $utilisateur->goals()
                ->where('type', GoalType::Measurement)
                ->where('measurement_type', $clef)
                ->exists();
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'part' => ['required', 'string', 'max:50'],
            'value' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'unit' => ['required', 'string', 'in:cm,in'],
            'measured_at' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * La borne haute d'une mesure est décimale : le message par défaut la
     * recopiait avec le point anglais (« 999.99 »), là où l'application écrit
     * 999,99 (#1975).
     *
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        return [
            'value.max' => 'Une mesure ne dépasse pas 999,99.',
        ];
    }
}
