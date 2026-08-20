<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La pagination de la liste des habitudes.
 *
 * Les regles etaient ecrites en ligne dans `Api\HabitController::index()` via
 * `Request::validate()`. C'est une macro : l'analyse statique lui donne
 * `mixed` en retour, donc plus rien ne verifiait ce qui entrait dans
 * `FetchHabitsIndexApiAction::execute()`, qui attend pourtant un
 * `array<string, mixed>` — erreur masquee dans le baseline (#1482).
 */
class IndexHabitsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'per_page' => 'sometimes|integer|min:1|max:100',
        ];
    }
}
