<?php

declare(strict_types=1);

namespace App\Http\Requests\Diagnostic;

use App\Http\Requests\Concerns\RefuseUneValeurEnJson;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

/**
 * `GET /diagnostic/v1/exceptions?depuis=` : un instant ISO 8601, ramené au
 * fuseau de l'application et borné à trente jours par `FenetreDeDiagnostic`.
 */
final class ListeDesExceptionsRequest extends FormRequest
{
    use RefuseUneValeurEnJson;

    public const int LONGUEUR_AU_PLUS = 64;

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
            'depuis' => ['bail', 'nullable', 'string', 'max:'.self::LONGUEUR_AU_PLUS],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validateur): void {
                $depuis = $this->input('depuis');

                if ($validateur->errors()->has('depuis') || ! is_string($depuis) || $depuis === '') {
                    return;
                }

                try {
                    FenetreDeDiagnostic::depuis($depuis, now());
                } catch (InvalidArgumentException) {
                    $validateur->errors()->add('depuis', FenetreDeDiagnostic::INSTANT_ATTENDU);
                }
            },
        ];
    }

    public function fenetre(CarbonInterface $maintenant): FenetreDeDiagnostic
    {
        $depuis = $this->validated('depuis');

        return FenetreDeDiagnostic::depuis(is_string($depuis) ? $depuis : null, $maintenant);
    }
}
