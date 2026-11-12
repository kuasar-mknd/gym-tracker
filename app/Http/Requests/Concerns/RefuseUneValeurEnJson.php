<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/**
 * Le refus d'une requête de validation du diagnostic (#2032). La garde a
 * déjà reconnu le jeton : la requête ne juge que les paramètres, et un
 * paramètre refusé rend toujours une 422 en JSON qui nomme les champs,
 * jamais la valeur reçue, ni une redirection.
 */
trait RefuseUneValeurEnJson
{
    protected function failedValidation(Validator $validator): void
    {
        $messages = $validator->errors()->messages();

        throw new HttpResponseException(new JsonResponse([
            'erreur' => 'parametre-invalide',
            'champs' => array_keys($messages),
            'messages' => collect($messages)->flatten()->values()->all(),
            'note' => EnveloppeDeDiagnostic::NOTE,
        ], 422));
    }
}
