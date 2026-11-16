<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Le verrou des séances du compte est resté pris plus longtemps que
 * l'attente permise (#2308).
 *
 * Levée par la seule prise du verrou `seances-du-compte`, jamais par ce que
 * la section fait : la séance n'a rien écrit, la requête peut être rejouée.
 * `bootstrap/app.php` la rend en 503 avec `Retry-After`.
 */
final class LeVerrouDesSeancesEstPris extends RuntimeException
{
    public const string MESSAGE = 'Une autre requête met à jour tes séances. Réessaie dans un instant.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
