<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\IntervalTimer;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIntervalTimerRequest extends FormRequest
{
    /**
     * L'autorisation vit dans le contrôleur ; le refus sur une ressource
     * d'autrui, validation comprise, est rendu en 404 par bootstrap/app.php.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Les bornes d'un minuteur d'intervalles (`IntervalTimer`) : au-delà, la
     * colonne (int) refusait l'écriture et la requête finissait en 500.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'work_seconds' => ['required', 'integer', 'min:1', 'max:'.IntervalTimer::SECONDES_MAX_PAR_INTERVALLE],
            'rest_seconds' => ['required', 'integer', 'min:0', 'max:'.IntervalTimer::SECONDES_MAX_PAR_INTERVALLE],
            'rounds' => ['required', 'integer', 'min:1', 'max:'.IntervalTimer::TOURS_MAX],
            'warmup_seconds' => ['nullable', 'integer', 'min:0', 'max:'.IntervalTimer::SECONDES_MAX_PAR_INTERVALLE],
        ];
    }
}
