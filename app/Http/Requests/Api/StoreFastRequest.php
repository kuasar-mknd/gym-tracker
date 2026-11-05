<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\RameneLesDatesAuFuseauDeLApplication;
use App\Models\Fast;
use App\Rules\DateQueSaColonneTient;
use Illuminate\Foundation\Http\FormRequest;

class StoreFastRequest extends FormRequest
{
    use RameneLesDatesAuFuseauDeLApplication;

    /**
     * L'avance que le début d'un jeûne peut prendre sur l'instant de la
     * requête, en minutes (#2198). Laissé tel quel, le champ « Début » part à
     * l'instant du geste, lu sur l'horloge de l'appareil, qui peut avancer un
     * peu sur celle du serveur ; au-delà, c'est une heure choisie dans le
     * futur, et la page comptait un écoulé négatif.
     */
    public const int AVANCE_TOLEREE_MINUTES = 5;

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
     * page affiche, et ne laisse aucun jeûne commencer dans le futur.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_time' => ['bail', 'required', 'date', DateQueSaColonneTient::horodatage(), self::regleDuDebutAuPlusTard()],
            'target_duration_minutes' => 'required|integer|min:1|max:'.Fast::DUREE_CIBLE_MAX_MINUTES,
            'type' => 'required|string|in:16:8,18:6,20:4,24:0,36:0,48:0,custom',
        ];
    }

    /**
     * La borne du début d'un jeûne, à la création comme à la modification
     * (`UpdateFastRequest`) : l'instant de la requête, lu une fois, plus
     * `AVANCE_TOLEREE_MINUTES`. Elle se pose après la plage de la colonne,
     * derrière `bail` : seule une date du calendrier s'y compare.
     */
    public static function regleDuDebutAuPlusTard(): string
    {
        return 'before_or_equal:'.now()->addMinutes(self::AVANCE_TOLEREE_MINUTES)->toIso8601String();
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
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->ramenerLesInstantsAuFuseauDeLApplicationAvecLeurDecalage(['start_time']);
    }
}
