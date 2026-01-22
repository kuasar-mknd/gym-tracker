<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFastingLogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // We handle strict ownership check in the Controller or Policy.
        // Returning true here allows the request to reach the controller validation.
        // Ideally we would use a Policy, but for this quick implementation we check logic in controller.
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
            'start_time' => ['sometimes', 'date'],
            'end_time' => ['nullable', 'date', 'after:start_time'],
            'target_duration_hours' => ['sometimes', 'numeric', 'min:1'],
            'type' => ['sometimes', 'string', 'max:50'],
            'status' => ['sometimes', 'string', 'in:active,completed,cancelled'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
