<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFastRequest extends FormRequest
{
    public function authorize(): bool
    {
        $fast = $this->route('fast');
        $user = $this->user();

        if (! $user instanceof \App\Models\User || ! $fast instanceof \App\Models\Fast) {
            return false;
        }

        return $user->id === $fast->user_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_time' => ['sometimes', 'date'],
            'end_time' => ['nullable', 'date'],
            'target_duration_minutes' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', 'string'],
            'status' => ['sometimes', 'required', 'string', 'in:active,completed,broken'],
        ];
    }
}
