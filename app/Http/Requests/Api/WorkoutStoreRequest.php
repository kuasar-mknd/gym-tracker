<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\RameneLesDatesAuFuseauDeLApplication;
use App\Rules\DateQueSaColonneTient;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Démarrer une séance, vide ou depuis un modèle, depuis la file hors ligne
 * (#2049). La clé du démarrage arrive dans l'en-tête `Idempotency-Key`, hors
 * du corps validé : elle nomme la tentative, pas la séance.
 */
class WorkoutStoreRequest extends FormRequest
{
    use RameneLesDatesAuFuseauDeLApplication;

    /**
     * La requête ne vérifie que la connexion ; l'autorisation vit dans le
     * contrôleur, et son refus est rendu en 404 par bootstrap/app.php.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Le début est l'heure du geste sur l'appareil, que l'action borne aux
     * trente jours passés (`Workout::debutBorne()`). Sa colonne est un
     * `DATETIME`, mais il devient la dernière séance du compte
     * (`users.last_workout_at`, un `TIMESTAMP`) : il prend la plage d'un
     * horodatage. Le modèle se cherche parmi ceux du compte : celui d'un
     * autre, ou un modèle disparu, reçoit le même refus. Il passe `bail` et
     * `integer` avant `exists` : un tableau imbriqué partait sinon dans
     * `whereIn()`, qui lève (#2071).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'started_at' => ['bail', 'sometimes', 'required', 'date', DateQueSaColonneTient::horodatage()],
            'name' => ['bail', 'sometimes', 'nullable', 'string', 'max:255'],
            'template_id' => [
                'bail',
                'nullable',
                'integer',
                Rule::exists('workout_templates', 'id')->where(function (Builder $query): void {
                    $query->where('user_id', $this->user()?->id);
                }),
            ],
        ];
    }

    /**
     * L'appareil envoie l'heure du geste en UTC : sans conversion, la séance
     * commençait à l'heure murale du décalage, relue comme une heure de Paris
     * (#1952).
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->ramenerLesInstantsAuFuseauDeLApplication(['started_at']);
    }
}
