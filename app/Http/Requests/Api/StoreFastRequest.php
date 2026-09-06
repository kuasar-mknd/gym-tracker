<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreFastRequest extends FormRequest
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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_time' => 'required|date',
            'target_duration_minutes' => 'required|integer|min:1',
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
}
