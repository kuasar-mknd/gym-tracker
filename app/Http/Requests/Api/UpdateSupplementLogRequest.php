<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplementLogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var \App\Models\User|null $user */
        $user = $this->user();

        if ($user === null) {
            return [];
        }

        return [
            'supplement_id' => [
                'sometimes',
                'integer',
                Rule::exists('supplements', 'id')->where('user_id', $user->id),
            ],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'consumed_at' => ['sometimes', 'date'],
        ];
    }
}
