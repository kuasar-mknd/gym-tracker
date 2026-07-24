<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplementLogRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->user();

        return [
            'supplement_id' => [
                'required',
                'integer',
                Rule::exists('supplements', 'id')->where(fn ($query) => $query->where('user_id', $user?->id)),
            ],
            'quantity' => ['required', 'integer', 'min:1'],
            'consumed_at' => ['required', 'date'],
        ];
    }
}
