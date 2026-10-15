<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use App\Services\Diagnostic\Vocabulaire;
use Illuminate\Support\Facades\Route;

/**
 * Le nom de route d'une exception et le gabarit de son chemin.
 *
 * Le nom vient du contexte que le paquet des exceptions garde (`route name`),
 * le gabarit du routeur de l'application, d'après ce nom : celui d'une route
 * nommée (`seances/{workout}`), jamais le chemin demandé, qui porte ce que
 * le client y a écrit.
 */
final class NomDeRoute
{
    public static function du(mixed $contexteEnJson): ?string
    {
        return Vocabulaire::forme(Vocabulaire::NOM_DE_ROUTE, JsonLu::tableau($contexteEnJson)['route name'] ?? null);
    }

    public static function gabarit(?string $nom): ?string
    {
        return Vocabulaire::forme(Vocabulaire::GABARIT_DE_ROUTE, Route::getRoutes()->getByName((string) $nom)?->uri());
    }
}
