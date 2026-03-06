<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkoutTemplateSetStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workout_template_line_id' => [
                'required',
                Rule::exists('workout_template_lines', 'id')->where(function ($query): void {
                    $query->whereIn('workout_template_id', function ($q): void {
                        $q->select('id')->from('workout_templates')->where('user_id', $this->user()?->id);
                    });
                }),
            ],
            'reps' => 'nullable|integer|min:0',
            'weight' => 'nullable|numeric|min:0',
            'is_warmup' => 'boolean',
            'order' => 'nullable|integer',
        ];
    }
}
