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

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'exercise_id' => $this->getExerciseIdRules(),
            'type' => 'required|string',
            'value' => 'required|numeric',
            'secondary_value' => 'nullable|numeric',
            'workout_id' => $this->getWorkoutIdRules(),
            'set_id' => $this->getSetIdRules(),
            'achieved_at' => 'required|date',
        ];
    }

    /** @return array<int, mixed> */
    private function getExerciseIdRules(): array
    {
        return [
            'required',
            Rule::exists('exercises', 'id')->where(function ($query): void {
                /** @var \App\Models\User|null $user */
                $user = $this->user();
                $query->where(function ($q) use ($user): void {
                    $q->whereNull('user_id')->orWhere('user_id', $user?->id);
                });
            }),
        ];
    }

    /** @return array<int, mixed> */
    private function getWorkoutIdRules(): array
    {
        return [
            'nullable',
            Rule::exists('workouts', 'id')->where(function ($query) {
                /** @var \App\Models\User $user */
                $user = $this->user();

                return $query->where('user_id', $user->id);
            }),
        ];
    }

    /** @return array<int, mixed> */
    private function getSetIdRules(): array
    {
        return [
            'nullable',
            Rule::exists('sets', 'id')->where(function ($query) {
                /** @var \App\Models\User $user */
                $user = $this->user();

                return $query->whereIn('workout_line_id', function ($q) use ($user): void {
                    $q->select('id')->from('workout_lines')->whereIn('workout_id', function ($q2) use ($user): void {
                        $q2->select('id')->from('workouts')->where('user_id', $user->id);
                    });
                });
            }),
        ];
    }
}
