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
            /*
             * L'appartenance voyage dans la regle, comme chez les freres de
             * cette famille. Le `exists` etait non borne : une ligne existante
             * appartenant a autrui passait la validation puis se faisait
             * refuser par la policy en 403, tandis qu'un identifiant inexistant
             * echouait en 422. Le meme oracle que #1418, sur un endpoint de
             * creation — dont on avait pourtant ecrit qu'ils etaient tous
             * etanches. Ils le sont maintenant.
             */
            'workout_template_line_id' => [
                'required',
                'integer',
                Rule::exists('workout_template_lines', 'id')->where(function (\Illuminate\Database\Query\Builder $query): void {
                    $query->whereIn('workout_template_id', function (\Illuminate\Database\Query\Builder $templates): void {
                        $templates->select('id')
                            ->from('workout_templates')
                            ->where('user_id', $this->user()?->id);
                    });
                }),
            ],
            'reps' => ['nullable', 'integer', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'is_warmup' => ['required', 'boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
