<?php

declare(strict_types=1);

namespace App\Models;

use BezhanSalleh\FilamentExceptions\Models\Exception;

/**
 * Une exception gardée en base pour le panneau, sans ce qu'une requête porte
 * de secret : les cookies ne sont pas gardés, et les champs et en-têtes
 * sensibles sont masqués avant l'écriture.
 */
final class ExceptionEnregistree extends Exception
{
    /** @var list<string> */
    private const array CLEFS_MASQUEES = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        '_token',
        'secret',
        'authorization',
        'cookie',
        'x-csrf-token',
        'x-xsrf-token',
    ];

    protected static function booted(): void
    {
        self::saving(function (self $exception): void {
            $exception->cookies = null;

            /** @var array<string, mixed>|null $corps */
            $corps = self::masquer($exception->body);
            $exception->body = $corps;

            /** @var array<string, array<int, string>|string> $entetes */
            $entetes = self::masquer($exception->headers) ?? [];
            $exception->headers = $entetes;
        });
    }

    /**
     * @param  array<mixed>|null  $valeurs
     * @return array<mixed>|null
     */
    private static function masquer(?array $valeurs): ?array
    {
        if ($valeurs === null) {
            return null;
        }

        foreach ($valeurs as $clef => $valeur) {
            if (is_string($clef) && in_array(strtolower($clef), self::CLEFS_MASQUEES, true)) {
                $valeurs[$clef] = '[masqué]';
            } elseif (is_array($valeur)) {
                $valeurs[$clef] = self::masquer($valeur);
            }
        }

        return $valeurs;
    }
}
