<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\WorkoutTemplateSet;
use App\Models\WorkoutTemplateLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkoutTemplateSetStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Let Controller policy handle this
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
                'integer',
                Rule::exists('workout_template_lines', 'id')->where(function ($query): void {
                    /** @var \Illuminate\Database\Query\Builder $query */
                    $query->whereIn('workout_template_id', function ($subQuery): void {
                        /** @var \Illuminate\Database\Query\Builder $subQuery */
                        $subQuery->select('id')
                            ->from('workout_templates')
                            ->where('user_id', $this->user()?->id);
                    });
                }),
            ],
            'reps' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'is_warmup' => ['boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
