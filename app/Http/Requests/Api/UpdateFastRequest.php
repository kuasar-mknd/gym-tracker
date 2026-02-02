<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFastRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $fast = $this->route('fast');
        return $this->user() instanceof \App\Models\User &&
               $fast instanceof \App\Models\Fast &&
               $this->user()->id === $fast->user_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'end_time' => ['nullable', 'date'],
            'status' => ['sometimes', 'required', 'string', 'in:active,completed,broken'],
        ];
    }
}
