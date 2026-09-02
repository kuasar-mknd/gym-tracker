<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La FORME seule. L'appartenance des series est verifiee dans le controleur,
 * apres l'autorisation : une lecture de base ici couterait une requete de plus
 * pour une ligne qui existe mais n'est pas a l'appelant que pour une ligne
 * inconnue. `ResourceDisclosureContractTest` compte ces requetes, parce qu'un
 * ecart de travail dit si la ressource existe aussi surement qu'un statut.
 */
class SetOrderRequest extends FormRequest
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
            'sets' => ['required', 'array', 'min:1', 'max:100'],
            'sets.*' => ['required', 'integer'],
        ];
    }
}
