<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RameneLesDatesAuFuseauDeLApplication;
use App\Rules\DateQueSaColonneTient;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkoutRequest extends FormRequest
{
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
     * Get the validation rules that apply to the request.
     *
     * Le début se change, il ne s'efface pas : la colonne le demande, et un
     * début vide ou nul passait la validation pour finir en erreur 500 à
     * l'écriture (#2071). Absent, il reste ce qu'il est. Sa colonne est un
     * `DATETIME`, mais il devient la dernière séance du compte
     * (`users.last_workout_at`, un `TIMESTAMP`) : il prend la plage d'un
     * horodatage (`DateQueSaColonneTient`).
     *
     * Il ne passe pas l'instant de la requête (#2049) : une séance au début
     * futur se fermait à ce début, à une heure qui n'était pas venue, la fin
     * étant bornée au plus tôt au début (`Workout::finBornee()`), et passait
     * pour en cours avant d'avoir commencé. Le refus se dit sur le champ, où
     * les réglages l'affichent. L'instant est celui que la fin reprend
     * (`maintenant()`).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'started_at' => [
                'bail',
                'sometimes',
                'required',
                'date',
                DateQueSaColonneTient::horodatage(),
                'before_or_equal:'.$this->maintenant()->toIso8601String(),
            ],
            'notes' => 'nullable|string|max:1000',
            'is_finished' => 'nullable|boolean',
        ];
    }

    /**
     * L'instant de la requête, lu une fois : la validation du début et la fin
     * de la séance le partagent (`UpdateWorkoutAction`, `.ai/rules/horloge.md`).
     */
    public function maintenant(): CarbonInterface
    {
        return $this->maintenant ??= now();
    }

    /**
     * Les réglages de la séance envoient son début en UTC : sans conversion, la
     * séance reculait de l'écart UTC à chaque enregistrement (#1952).
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->ramenerLesInstantsAuFuseauDeLApplication(['started_at']);
    }
}
