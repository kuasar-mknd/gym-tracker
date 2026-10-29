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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_time' => ['bail', 'required', 'date', DateQueSaColonneTient::horodatage()],
            'target_duration_minutes' => 'required|integer|min:1|max:'.Fast::DUREE_CIBLE_MAX_MINUTES,
            'type' => 'required|string|in:16:8,18:6,20:4,24:0,36:0,48:0,custom',
        ];
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
     * fuseau.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->ramenerLesInstantsAuFuseauDeLApplication(['start_time']);
    }
}
