<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\MesureLAgeDuGeste;
use App\Http\Requests\Concerns\RameneLesDatesAuFuseauDeLApplication;
use App\Models\Fast;
use App\Rules\DateQueSaColonneTient;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFastRequest extends FormRequest
{
    use MesureLAgeDuGeste;
    use RameneLesDatesAuFuseauDeLApplication;

    /** L'instant de la requête, lu une fois (`maintenant()`). */
    private ?CarbonInterface $maintenant = null;

    /**
     * L'autorisation vit dans le contrôleur ; le refus sur une ressource
     * d'autrui, validation comprise, est rendu en 404 par bootstrap/app.php.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * La durée cible est bornée comme à la création
     * (`Fast::DUREE_CIBLE_MAX_MINUTES`), et le début aussi : un début déplacé
     * ne passe pas l'instant de la requête de plus de
     * `StoreFastRequest::AVANCE_TOLEREE_MINUTES` (#2198). La fin n'est pas
     * jugée : la page l'envoie à l'instant du geste, sans champ pour la
     * corriger, et un jeûne terminé avant son début y dure zéro. Une page
     * chargée avant le correctif l'envoie sans l'heure de l'appareil au départ,
     * telle que l'horloge de l'appareil la lit, en avance ou non.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_time' => ['bail', 'sometimes', 'date', DateQueSaColonneTient::horodatage(), StoreFastRequest::regleDuDebutAuPlusTard($this->maintenant())],
            'end_time' => ['bail', 'nullable', 'date', DateQueSaColonneTient::horodatage()],
            'target_duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:'.Fast::DUREE_CIBLE_MAX_MINUTES],
            'type' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'string', 'in:active,completed,broken'],
        ];
    }

    /**
     * L'instant de la requête, lu une fois : la fin prise au geste s'y place,
     * et la borne du début s'y mesure (`.ai/rules/horloge.md`).
     */
    public function maintenant(): CarbonInterface
    {
        return $this->maintenant ??= now();
    }

    /**
     * Un début ou une fin envoyés avec un décalage sont des instants, relus dans
     * le fuseau de l'application (#1952). La page du jeûne envoie sa fin ainsi
     * depuis #2111, par `toISOString()` : une heure murale sans décalage, celle
     * d'une page chargée avant, reste lue comme une heure de l'application, ce
     * qui n'est juste que pour un appareil dans ce fuseau. Les instants ramenés
     * gardent leur décalage, que le modèle écrit (#2159).
     *
     * La fin part avec l'heure de l'appareil au départ (#2198) : son âge se
     * mesure d'abord, puis elle devient l'instant du geste sur l'horloge du
     * serveur (`StoreFastRequest::instantDuGeste()`), comme le début laissé
     * tel quel. Le début et la fin se lisent ainsi sur la même horloge, et la
     * durée ne prend pas l'écart de celle de l'appareil.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->mesurerLAgeDuGeste('end_time');

        if ($this->ageDuGeste() !== null) {
            $this->merge(['end_time' => StoreFastRequest::instantDuGeste($this->ageDuGeste(), $this->maintenant())]);
        }

        $this->ramenerLesInstantsAuFuseauDeLApplicationAvecLeurDecalage(['start_time', 'end_time']);
    }
}
