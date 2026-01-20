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
            'workout_id' => [
                'nullable',
                Rule::exists('workouts', 'id')->where(function ($query) {
                    return $query->where('user_id', $this->user()->id);
                }),
            ],
            'set_id' => [
                'nullable',
                Rule::exists('sets', 'id')->where(function ($query) {
                    return $query->whereIn('workout_line_id', function ($q) {
                        $q->select('id')->from('workout_lines')->whereIn('workout_id', function ($q2) {
                            $q2->select('id')->from('workouts')->where('user_id', $this->user()->id);
                        });
                    });
                }),
            ],
            'achieved_at' => 'required|date',
        ];
    }
}
