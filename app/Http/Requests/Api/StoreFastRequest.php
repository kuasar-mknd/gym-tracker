<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\MesureLAgeDuGeste;
use App\Http\Requests\Concerns\RameneLesDatesAuFuseauDeLApplication;
use App\Models\Fast;
use App\Models\Workout;
use App\Rules\DateQueSaColonneTient;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;

class StoreFastRequest extends FormRequest
{
    use MesureLAgeDuGeste;
    use RameneLesDatesAuFuseauDeLApplication;

    /**
     * L'avance que le début d'un jeûne peut prendre sur l'instant de la
     * requête, en minutes (#2198). Une page chargée avant le correctif envoie
     * le début laissé tel quel à l'instant du geste, lu sur l'horloge de
     * l'appareil, qui peut avancer un peu sur celle du serveur, et sans l'heure
     * de l'appareil au départ ; au-delà, c'est une heure choisie dans le futur,
     * et la page comptait un écoulé négatif.
     */
    public const int AVANCE_TOLEREE_MINUTES = 5;

    /** L'instant de la requête, lu une fois (`maintenant()`). */
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
     * La durée cible est bornée (`Fast::DUREE_CIBLE_MAX_MINUTES`), à la
     * création comme à la modification : au-delà de la colonne (int), la
     * requête finissait en 500.
     *
     * Le début ne passe pas l'instant de la requête de plus de
     * `AVANCE_TOLEREE_MINUTES` (#2198) : le refus se dit sur le champ, que la
     * page affiche, et ne laisse aucun jeûne commencer dans le futur. Un début
     * pris au geste est déjà placé sur l'horloge du serveur
     * (`prepareForValidation()`) : la borne ne juge qu'une heure tapée, ou
     * envoyée sans l'heure de l'appareil au départ.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_time' => ['bail', 'required', 'date', DateQueSaColonneTient::horodatage(), self::regleDuDebutAuPlusTard($this->maintenant())],
            'target_duration_minutes' => 'required|integer|min:1|max:'.Fast::DUREE_CIBLE_MAX_MINUTES,
            'type' => 'required|string|in:16:8,18:6,20:4,24:0,36:0,48:0,custom',
        ];
    }

    /**
     * La borne du début d'un jeûne, à la création comme à la modification
     * (`UpdateFastRequest`) : l'instant de la requête, lu une fois
     * (`maintenant()`), plus `AVANCE_TOLEREE_MINUTES`. Elle se pose après la
     * plage de la colonne, derrière `bail` : seule une date du calendrier s'y
     * compare.
     */
    public static function regleDuDebutAuPlusTard(CarbonInterface $maintenant): string
    {
        return 'before_or_equal:'.$maintenant->copy()->addMinutes(self::AVANCE_TOLEREE_MINUTES)->toIso8601String();
    }

    /**
     * Un instant pris au geste sur l'appareil (le début laissé tel quel, la
     * fin), placé sur l'horloge du serveur (#2198) : maintenant, moins l'âge
     * du geste au départ de la requête, lu sur la seule horloge de l'appareil
     * (`MesureLAgeDuGeste`), comme le démarrage d'une séance
     * (`Workout::debutBorne()`, `.ai/rules/horloge.md`) ; un âge négatif, ou
     * plus vieux que ce que la file des séances garde, revient à maintenant.
     * La page envoie l'heure de l'appareil au départ égale à celle du geste,
     * d'un âge nul : le geste prend l'instant de la requête, et un appareil en
     * avance de plus de `AVANCE_TOLEREE_MINUTES` n'est plus refusé. Rendu avec
     * son décalage, que `ramenerLesInstantsAuFuseauDeLApplicationAvecLeurDecalage()`
     * garde : un geste de la première occurrence de l'heure que le passage à
     * l'heure d'hiver répète y reste (#2159).
     */
    public static function instantDuGeste(int $ageDuGeste, CarbonInterface $maintenant): string
    {
        return Workout::debutBorne($ageDuGeste, $maintenant)->toIso8601String();
    }

    /**
     * L'instant de la requête, lu une fois : le début pris au geste s'y place,
     * et la borne du début s'y mesure (`.ai/rules/horloge.md`).
     */
    public function maintenant(): CarbonInterface
    {
        return $this->maintenant ??= now();
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            /** @var \App\Models\User|null $user */
            $user = $this->user();

            if ($user !== null && $user->fasts()->where('status', 'active')->exists()) {
                $validator->errors()->add('base', 'Un jeûne est déjà en cours.');
            }
        });
    }

    /**
     * Un début envoyé avec un décalage est un instant, relu dans le fuseau de
     * l'application (#1952). La page du jeûne l'envoie ainsi depuis #2111 :
     * l'instant du geste par `toISOString()` quand le champ est resté tel
     * qu'elle l'a rempli, sinon l'heure du champ par `formatToUTC()`. Une heure
     * murale sans décalage, celle d'une page chargée avant, reste lue comme une
     * heure de l'application, ce qui n'est juste que pour un appareil dans ce
     * fuseau. L'instant ramené garde son décalage, que le modèle écrit (#2159) :
     * pris dans la première occurrence de l'heure que le passage à l'heure
     * d'hiver répète, il se relisait sinon une heure plus tard.
     *
     * Un début laissé tel quel part avec l'heure de l'appareil au départ
     * (#2198) : son âge se mesure d'abord, sur l'instant tel que l'appareil
     * l'envoie, puis le début devient l'instant du geste sur l'horloge du
     * serveur (`instantDuGeste()`), avant d'être ramené et validé. Sans heure
     * de l'appareil lisible, le début reste celui qui est envoyé.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->mesurerLAgeDuGeste('start_time');

        if ($this->ageDuGeste() !== null) {
            $this->merge(['start_time' => self::instantDuGeste($this->ageDuGeste(), $this->maintenant())]);
        }

        $this->ramenerLesInstantsAuFuseauDeLApplicationAvecLeurDecalage(['start_time']);
    }
}
