<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RameneLesDatesAuFuseauDeLApplication;
use Illuminate\Foundation\Http\FormRequest;

class BodyPartMeasurementStoreRequest extends FormRequest
{
    use RameneLesDatesAuFuseauDeLApplication;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'part' => ['required', 'string', 'max:50'],
            'value' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'unit' => ['required', 'string', 'in:cm,in'],
            'measured_at' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Une date envoyée avec un décalage désigne le jour de Paris de cet instant,
     * et c'est ce jour que `before_or_equal:today` doit juger (#1952).
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->ramenerLesJoursAuFuseauDeLApplication(['measured_at']);
    }
}
