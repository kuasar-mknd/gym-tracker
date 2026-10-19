<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\RameneLesDatesAuFuseauDeLApplication;
use App\Rules\DateQueSaColonneTient;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Terminer une séance depuis la file hors ligne (#2049) : la séance se nomme
 * dans le corps, pas dans l'adresse, parce qu'une séance encore en file n'a
 * pas d'identifiant à y mettre, et que la file ne remplace la référence
 * `{ enAttenteDe }` que dans le corps.
 */
class WorkoutFinishRequest extends FormRequest
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
     * La séance se cherche parmi celles du compte : celle d'un autre et une
     * séance absente reçoivent le même refus, sans dire laquelle existe. Elle
     * passe `bail` et `integer` avant `exists` : une référence restée
     * `{ enAttenteDe }`, ou un tableau imbriqué, ne va jamais jusqu'à la base
     * (#2071). La fin est l'heure du geste sur l'appareil, que l'action borne
     * entre le début et maintenant (`Workout::finBornee()`) ; sa colonne est
     * un `DATETIME`.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workout_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('workouts', 'id')->where(function (Builder $query): void {
                    $query->where('user_id', $this->user()?->id);
                }),
            ],
            'ended_at' => ['bail', 'sometimes', 'required', 'date', DateQueSaColonneTient::date()],
        ];
    }

    /**
     * L'appareil envoie l'heure du geste en UTC (#1952).
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->ramenerLesInstantsAuFuseauDeLApplication(['ended_at']);
    }
}
