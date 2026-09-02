<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La FORME seule. L'appartenance des lignes est verifiee dans le controleur,
 * apres l'autorisation, et c'est deliberе : une requete de base ici couterait
 * une lecture de plus pour une seance qui existe mais n'est pas a l'appelant
 * que pour une seance qui n'existe pas. `ResourceDisclosureContractTest` compte
 * ces requetes, parce qu'un ecart de travail dit si la ressource existe aussi
 * surement qu'un code de statut.
 */
class WorkoutLineOrderRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*' => ['required', 'integer'],
        ];
    }
}
