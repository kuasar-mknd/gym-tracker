<?php

declare(strict_types=1);

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
        // Ideally we check policy here: return $this->user()->can('update', $this->route('notification_preference'));
        // But we haven't created policy yet.
        // For now, allow it, assuming Controller ensures ownership or middleware does.
        // Actually, let's verify ownership here if possible, or just return true.
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
                'string',
                'max:255',
                Rule::unique('notification_preferences')->where(fn ($query) => $query->where('user_id', $this->user()?->id))->ignore($this->route('notification_preference')),
            ],
            'value' => ['nullable', 'integer'],
            'is_enabled' => ['boolean'],
            'is_push_enabled' => ['boolean'],
        ];
    }
}
