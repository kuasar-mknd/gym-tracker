<?php

declare(strict_types=1);

namespace App\Http\Requests\Diagnostic;

use App\Http\Requests\Concerns\RefuseUneValeurEnJson;
use App\Services\Diagnostic\Projections\ProjectionDesExceptions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /diagnostic/v1/exceptions/{id}/occurrences?avant=` : le curseur, un
 * identifiant d'exception borné ; la taille de la page n'est jamais choisie.
 */
final class OccurrencesDUneExceptionRequest extends FormRequest
{
    use RefuseUneValeurEnJson;

    /**
     * La garde a reconnu le jeton avant : il n'y a pas de compte à vérifier.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'avant' => ['bail', 'nullable', 'integer', 'min:1', 'max:'.ProjectionDesExceptions::IDENTIFIANT_AU_PLUS],
        ];
    }

    public function curseur(): ?int
    {
        $avant = $this->validated('avant');

        return is_numeric($avant) ? (int) $avant : null;
    }
}
