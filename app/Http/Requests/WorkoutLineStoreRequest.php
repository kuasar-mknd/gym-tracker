<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkoutLineStoreRequest extends FormRequest
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
            'exercise_id' => [
                'required',
                Rule::exists('exercises', 'id')->where(function ($query) {
                    $query->where(function ($q) {
                        $q->whereNull('user_id')
                            ->orWhere('user_id', $this->user()?->id);
                    });
                }),
            ],
        ];
    }
}
