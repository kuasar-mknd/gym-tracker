<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\MesureLAgeDuGeste;
use App\Http\Requests\Concerns\RameneLesDatesAuFuseauDeLApplication;
use App\Rules\DateQueSaColonneTient;
use App\Rules\FinChoisieDansLaSeance;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
    use MesureLAgeDuGeste;
    use RameneLesDatesAuFuseauDeLApplication;

    /** L'instant de la requête, lu une fois, et seulement quand `fin_choisie` est jugée (`maintenant()`). */
    private ?CarbonInterface $maintenant = null;

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
     * (#2071). La fin est l'heure du geste sur l'appareil, dont seul l'âge au
     * départ de la requête compte (`ageDuGeste()`, `HeureDeLAppareil`), que
     * l'action place sur sa lecture de l'horloge et borne entre le début et
     * maintenant (`Workout::finBornee()`) ; sa colonne est un `DATETIME`.
     *
     * `fin_choisie` est l'heure que la personne a choisie en terminant une
     * séance oubliée (#2319) : une heure absolue, comme le réglage du début,
     * que l'horloge de l'appareil ne date pas. Elle ne précède pas le début
     * de la séance et ne passe pas maintenant ; absente, la fin est celle du
     * geste.
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
            'fin_choisie' => [
                'bail',
                'nullable',
                'date',
                DateQueSaColonneTient::date(),
                new FinChoisieDansLaSeance($this->input('workout_id'), $this->user()?->id, $this->maintenant(...)),
            ],
        ];
    }

    /**
     * L'instant de la requête, lu une fois, et seulement quand la fin choisie
     * est jugée (`FinChoisieDansLaSeance`) : l'action le reprend pour la borner
     * (`instantLu()`).
     */
    public function maintenant(): CarbonInterface
    {
        return $this->maintenant ??= now();
    }

    /**
     * L'instant que la validation a lu, ou null quand elle n'a rien jugé contre
     * maintenant : l'action lit alors l'horloge elle-même, une seule fois.
     */
    public function instantLu(): ?CarbonInterface
    {
        return $this->maintenant;
    }

    /**
     * L'heure choisie, comme un instant, ou null quand la fin est celle du geste.
     */
    public function finChoisie(): ?CarbonImmutable
    {
        $valeur = $this->validated('fin_choisie');

        return is_string($valeur) ? CarbonImmutable::parse($valeur) : null;
    }

    /**
     * L'âge du geste se mesure d'abord, sur l'instant tel que l'appareil
     * l'envoie, puis l'instant, en UTC, revient au fuseau de l'application
     * (#1952).
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->mesurerLAgeDuGeste('ended_at');
        $this->ramenerLesInstantsAuFuseauDeLApplication(['ended_at']);
        $this->ramenerLesInstantsAuFuseauDeLApplicationAvecLeurDecalage(['fin_choisie']);
    }
}
