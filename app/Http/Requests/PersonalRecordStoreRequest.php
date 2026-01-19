<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PersonalRecordStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exercise_id' => [
                'required',
                Rule::exists('exercises', 'id')->where(function ($query) {
                    $query->where(function ($q) {
                        $q->whereNull('user_id')->orWhere('user_id', $this->user()->id);
                    });
                }),
            ],
            'type' => 'required|string',
            'value' => 'required|numeric',
            'secondary_value' => 'nullable|numeric',
            'workout_id' => 'nullable|exists:workouts,id',
            'set_id' => 'nullable|exists:sets,id',
            'achieved_at' => 'required|date',
        ];
    }
}
