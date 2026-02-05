<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\IntervalTimer;
use Illuminate\Foundation\Http\FormRequest;

class StoreIntervalTimerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = $this->user();

        return $user?->can('create', IntervalTimer::class) ?? false;
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
