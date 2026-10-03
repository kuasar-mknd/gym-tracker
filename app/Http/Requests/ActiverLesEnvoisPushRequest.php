<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActiverLesEnvoisPushRequest extends FormRequest
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
     * Les types dont l'envoi push s'allume.
     *
     * Les records seuls, par décision du 2026-10-03 (#1848) : l'invitation de
     * fin de séance parle d'un record à annoncer, et les rappels d'entraînement
     * restent à activer dans le profil, avec leurs jours.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['string', 'distinct', Rule::in(['personal_record'])],
        ];
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        /** @var list<string> $types */
        $types = $this->validated('types');

        return $types;
    }
}
