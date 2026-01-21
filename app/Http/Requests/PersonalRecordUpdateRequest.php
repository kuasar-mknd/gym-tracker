<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PersonalRecordUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('personal_record')) ?? false;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'exercise_id' => [
                'sometimes',
                Rule::exists('exercises', 'id')->where(function ($query): void {
                    $query->where(function ($q): void {
                        $q->whereNull('user_id')->orWhere('user_id', $this->user()?->id);
                    });
                }),
            ],
            'type' => 'sometimes|string',
            'value' => 'sometimes|numeric',
            'secondary_value' => 'nullable|numeric',
            'workout_id' => 'nullable|exists:workouts,id',
            'set_id' => 'nullable|exists:sets,id',
            'achieved_at' => 'sometimes|date',
        ];
    }
}
