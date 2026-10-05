<?php

declare(strict_types=1);

namespace App\Http\Requests;

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
     * les noms français, un francophone a pu saisir « Taille » à la main, et
     * la page de détail de cette partie renvoie ce nom. Le ramener à la clef
     * couperait son historique en deux, la nouvelle mesure manquant à la page
     * où on vient de l'ajouter (#1974). La comparaison suit la collation de la
     * colonne, comme le regroupement de la page Mensurations.
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

        if ($clef === $partie || $this->leCompteMesureDejaLaPartie($partie)) {
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

}
