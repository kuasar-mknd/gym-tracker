<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PersonalRecordType;
use Illuminate\Database\Query\Builder;
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
            'exercise_id' => $this->getExerciseIdRules(),
            'type' => ['sometimes', Rule::enum(PersonalRecordType::class)],
            'value' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'secondary_value' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'workout_id' => $this->getWorkoutIdRules(),
            'set_id' => $this->getSetIdRules(),
            'achieved_at' => 'sometimes|date',
        ];
    }

    /** @return array<int, mixed> */
    private function getExerciseIdRules(): array
    {
        return [
            'sometimes',
            Rule::exists('exercises', 'id')->where(function (Builder $query): void {
                /** @var \App\Models\User|null $user */
                $user = $this->user();
                $query->where(function (Builder $q) use ($user): void {
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
            Rule::exists('workouts', 'id')->where(function (Builder $query) {
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
            Rule::exists('sets', 'id')->where(function (Builder $query) {
                /** @var \App\Models\User $user */
                $user = $this->user();

                return $query->whereIn('workout_line_id', function (Builder $q) use ($user): void {
                    $q->select('id')->from('workout_lines')->whereIn('workout_id', function (Builder $q2) use ($user): void {
                        $q2->select('id')->from('workouts')->where('user_id', $user->id);
                    });
                });
            }),
        ];
    }
}
