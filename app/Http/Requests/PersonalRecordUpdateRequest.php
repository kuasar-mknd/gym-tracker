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
            'achieved_at' => 'sometimes|date',
        ];
    }
}
