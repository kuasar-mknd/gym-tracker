<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMacroCalculationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('macro_calculation'));
    }

    public function rules(): array
    {
        return [
            'gender' => ['required', 'string', 'in:male,female'],
            'age' => ['required', 'integer', 'min:10', 'max:100'],
            'height' => ['required', 'numeric', 'min:50', 'max:300'],
            'weight' => ['required', 'numeric', 'min:20', 'max:300'],
            'activity_level' => ['required', 'string', 'in:sedentary,light,moderate,very,extra'],
            'goal' => ['required', 'string', 'in:cut,maintain,bulk'],
        ];
    }
}
