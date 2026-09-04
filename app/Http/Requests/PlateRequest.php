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
}
