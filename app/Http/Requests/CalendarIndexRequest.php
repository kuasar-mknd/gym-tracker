<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalendarIndexRequest extends FormRequest
{
    /**
     * La requête ne vérifie que la connexion ; l'autorisation vit dans le
     * contrôleur, et son refus est rendu en 404 par bootstrap/app.php.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * L'année affichée va de 1970, le début des horodatages de la base, à
     * 2100 : un calendrier de séances n'a rien à montrer au-delà.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'year' => ['nullable', 'integer', 'min:1970', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ];
    }
}
