<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkoutLineStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workoutId = $this->input('workout_id');

        if (! $workoutId) {
            return true;
        }

        /** @var \App\Models\Workout|null $workout */
        $workout = Workout::find($workoutId);

        if (! $workout) {
            return true;
        }

        /** @var User $user */
        $user = $this->user();

        return $workout->user_id === $user->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workout_id' => ['required', 'exists:workouts,id'],
            'exercise_id' => [
                'required',
                Rule::exists('exercises', 'id')->where(function ($query): void {
                    /** @var \Illuminate\Database\Eloquent\Builder $query */
                    $query->where(function ($q): void {
                        /** @var \Illuminate\Database\Eloquent\Builder $q */
                        $q->whereNull('user_id')
                            ->orWhere('user_id', $this->user()?->id);
                    });
                }),
            ],
            'notes' => ['nullable', 'string'],
        ];
    }
}
