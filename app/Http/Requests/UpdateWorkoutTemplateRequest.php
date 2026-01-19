<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkoutTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled by the controller's $this->authorize('update', $template) call
        // or we could move it here:
        // return $this->user()->can('update', $this->route('workout_template'));
        // But since we are cleaning up the controller, let's keep it simple and consistent with other requests
        // that rely on controller authorization or just check auth status.
        // However, StoreWorkoutTemplateRequest checks if user != null.
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'exercises' => 'nullable|array',
            'exercises.*.id' => [
                'required',
                'integer',
                Rule::exists('exercises', 'id')->where(function ($query) {
                    $query->where('user_id', $this->user()->id)
                        ->orWhereNull('user_id');
                }),
            ],
            'exercises.*.sets' => 'nullable|array',
            'exercises.*.sets.*.reps' => 'nullable|integer',
            'exercises.*.sets.*.weight' => 'nullable|numeric',
            'exercises.*.sets.*.is_warmup' => 'nullable|boolean',
        ];
    }
}
