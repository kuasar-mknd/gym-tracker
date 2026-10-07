<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Création et modification d'un disque : mêmes champs, mêmes bornes.
 */
class PlateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'weight' => ['required', 'numeric', 'min:0.1', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * La borne basse d'un disque est décimale : le message par défaut la
     * recopiait avec le point anglais (« 0.1 »), là où l'application écrit
     * 0,1 (#1975).
     *
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        return [
            'weight.min' => 'Un disque pèse au moins 0,1 kg.',
        ];
    }
}
