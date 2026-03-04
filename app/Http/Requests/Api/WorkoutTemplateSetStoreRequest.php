<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class WorkoutTemplateSetStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'workout_template_line_id' => ['required', 'integer', 'exists:workout_template_lines,id'],
            'reps' => ['nullable', 'integer', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'is_warmup' => ['boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
