<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PersonalRecordStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exercise_id' => 'required|exists:exercises,id',
            'type' => 'required|string',
            'value' => 'required|numeric',
            'secondary_value' => 'nullable|numeric',
            'workout_id' => 'nullable|exists:workouts,id',
            'set_id' => 'nullable|exists:sets,id',
            'achieved_at' => 'required|date',
        ];
    }
}
