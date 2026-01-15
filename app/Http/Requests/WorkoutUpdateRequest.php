<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkoutUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('workout'));
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date|after_or_equal:started_at',
            'notes' => 'nullable|string',
        ];
    }
}
