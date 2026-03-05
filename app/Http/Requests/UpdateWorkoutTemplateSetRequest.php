<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkoutTemplateSetRequest extends FormRequest
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
                'sometimes',
                'integer',
                Rule::exists('workout_template_lines', 'id')->where(function (\Illuminate\Database\Query\Builder $query): void {
                    $query->whereIn('workout_template_id', function (\Illuminate\Database\Query\Builder $subQuery): void {
                        $subQuery->select('id')
                            ->from('workout_templates')
                            ->where('user_id', $this->user()?->getAuthIdentifier());
                    });
                }),
            ],
            'reps' => ['nullable', 'integer', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'is_warmup' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'integer'],
        ];
    }
}
