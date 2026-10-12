<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

/**
 * La famille d'un navigateur, d'après sa chaîne d'agent : une liste fermée
 * (edge, opera, samsung, chrome, firefox, safari, autre).
 * La chaîne elle-même, écrite par le client, ne sort jamais.
 */
final class FamilleDeNavigateur
{
    public static function de(mixed $agent): string
    {
        $texte = is_string($agent) ? $agent : '';

        return match (true) {
            str_contains($texte, 'Edg/') => 'edge',
            str_contains($texte, 'OPR/') => 'opera',
            str_contains($texte, 'SamsungBrowser/') => 'samsung',
            str_contains($texte, 'Chrome/'), str_contains($texte, 'CriOS/') => 'chrome',
            str_contains($texte, 'Firefox/'), str_contains($texte, 'FxiOS/') => 'firefox',
            str_contains($texte, 'Safari/') => 'safari',
            default => 'autre',
        };
    }
}
