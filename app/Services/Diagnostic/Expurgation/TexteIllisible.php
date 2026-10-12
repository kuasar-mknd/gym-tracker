<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Expurgation;

use RuntimeException;

/**
 * Une étape de l'expurgation n'a pas pu lire le texte : une expression a
 * échoué (limite de retour arrière, UTF-8 invalide) ou la normalisation a
 * refusé l'entrée. Le champ entier devient alors `[illisible]`, jamais le
 * texte d'origine (#2032).
 */
final class TexteIllisible extends RuntimeException
{
}
