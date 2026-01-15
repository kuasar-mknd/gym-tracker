<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PersonalRecordUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('personal_record'));
    }

    public function rules(): array
    {
        return [
            'exercise_id' => 'sometimes|exists:exercises,id',
            'type' => 'sometimes|string',
            'value' => 'sometimes|numeric',
            'secondary_value' => 'nullable|numeric',
            'workout_id' => 'nullable|exists:workouts,id',
            'set_id' => 'nullable|exists:sets,id',
            'achieved_at' => 'sometimes|date',
        ];
    }
}
