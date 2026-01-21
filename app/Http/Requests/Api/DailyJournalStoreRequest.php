<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\DailyJournalStoreRequest as BaseDailyJournalStoreRequest;
use Illuminate\Validation\Rule;

class DailyJournalStoreRequest extends BaseDailyJournalStoreRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['date'] = [
            'required',
            'date',
            Rule::unique('daily_journals')->where(fn ($query) => $query->where('user_id', $this->user()?->id)),
        ];

        return $rules;
    }
}
