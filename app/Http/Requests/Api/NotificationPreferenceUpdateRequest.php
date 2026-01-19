<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationPreferenceUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled in the controller via policies or checking user_id
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
            'type' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('notification_preferences')
                    ->ignore($this->route('notification_preference'))
                    ->where(fn ($query) => $query->where('user_id', $this->user()->id)),
            ],
            'is_enabled' => ['sometimes', 'required', 'boolean'],
            'is_push_enabled' => ['sometimes', 'required', 'boolean'],
            'value' => ['nullable', 'integer'],
        ];
    }
}
