<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFastRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
            'end_time' => ['nullable', 'date'],
            'status' => ['required', 'string', 'in:active,completed,broken'],
        ];
    }
}
