<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\MesureLAgeDuGeste;
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
    use MesureLAgeDuGeste;
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
     * Le début est l'heure du geste sur l'appareil, dont seul l'âge au départ
     * de la requête compte (`ageDuGeste()`, `HeureDeLAppareil`), que l'action
     * place sur sa lecture de l'horloge et borne aux trente jours passés
     * (`Workout::debutBorne()`). Sa colonne est un `DATETIME`, mais il
     * devient la dernière séance du compte (`users.last_workout_at`, un
     * `TIMESTAMP`) : il prend la plage d'un horodatage. Le modèle se cherche parmi ceux du compte : celui d'un
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
     * L'âge du geste se mesure d'abord, sur l'instant tel que l'appareil
     * l'envoie, puis l'instant, en UTC, revient au fuseau de l'application,
     * comme tout champ `date` (#1952).
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->mesurerLAgeDuGeste('started_at');
        $this->ramenerLesInstantsAuFuseauDeLApplication(['started_at']);
    }
}
