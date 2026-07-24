<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\Fast;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFastRequest extends FormRequest
{
    public function authorize(): bool
    {
        $fast = $this->route('fast');
        $user = $this->user();

        if (! $user instanceof User || ! $fast instanceof Fast) {
            return false;
        }

        return $user->id === $fast->user_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_time' => ['sometimes', 'date'],
            'end_time' => ['nullable', 'date'],
            'target_duration_minutes' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'string', 'in:active,completed,broken'],
        ];
    }
}
