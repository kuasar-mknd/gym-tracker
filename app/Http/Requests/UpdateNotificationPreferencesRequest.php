<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
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
            'preferences' => [
                'required',
                'array',
                'bail',
                $this->getPreferenceTypesValidationRule(),
            ],
            'preferences.*' => ['boolean'],
            'push_preferences' => ['required', 'array'],
            'push_preferences.*' => ['boolean'],
            'values' => ['nullable', 'array'],
            'values.*' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }

    private function getPreferenceTypesValidationRule(): \Closure
    {
        $allowedTypes = [
            'daily_reminder',
            'workout_streak_reminder',
            'no_activity_reminder',
            'weekly_summary',
            'achievement_unlocked',
            'goal_progress',
            'personal_record',
            'training_reminder',
        ];

        return function ($attribute, $value, $fail) use ($allowedTypes): void {
            $keys = array_keys($value);
            $diff = array_diff($keys, $allowedTypes);
            if ($diff !== []) {
                $fail('Invalid preference types: '.implode(', ', $diff));
            }
        };
    }
}
