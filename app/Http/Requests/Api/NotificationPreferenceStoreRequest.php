<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Database\Query\Builder;
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
                Rule::unique('notification_preferences')->where(fn (Builder $query) => $query->where('user_id', $this->user()?->id)),
            ],
            'value' => ['nullable', 'integer'],
            'days' => ['nullable', 'array', 'min:1', 'max:7'],
            'days.*' => ['integer', 'between:1,7', 'distinct'],
            'is_enabled' => ['boolean'],
            'is_push_enabled' => ['boolean'],
        ];
    }
}
