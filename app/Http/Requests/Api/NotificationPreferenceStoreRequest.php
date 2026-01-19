<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationPreferenceStoreRequest extends FormRequest
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
            'type' => [
                'required',
                'string',
                'max:255',
                Rule::unique('notification_preferences')
                    ->where(fn ($query) => $query->where('user_id', $this->user()->id)),
            ],
            'is_enabled' => ['required', 'boolean'],
            'is_push_enabled' => ['required', 'boolean'],
            'value' => ['nullable', 'integer'],
        ];
    }
}
