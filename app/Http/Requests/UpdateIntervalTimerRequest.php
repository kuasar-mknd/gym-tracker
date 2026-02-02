<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIntervalTimerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $intervalTimer = $this->route('intervalTimer');
        $user = $this->user();

        if (! $user instanceof \App\Models\User || ! $intervalTimer instanceof \App\Models\IntervalTimer) {
            return false;
        }

        return $user->id === $intervalTimer->user_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'work_seconds' => ['required', 'integer', 'min:1'],
            'rest_seconds' => ['required', 'integer', 'min:0'],
            'rounds' => ['required', 'integer', 'min:1'],
            'warmup_seconds' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
