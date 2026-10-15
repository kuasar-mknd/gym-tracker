<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Une exception que l'autochargeur de Composer sait trouver, et que seul un
 * test du diagnostic nomme, sans jamais la charger (#2032) : il vérifie que
 * le nom d'une classe lu en base est reconnu sans qu'aucun fichier soit
 * chargé pour le savoir.
 */
final class ExceptionQueLeDiagnosticNeChargePas extends RuntimeException
{
}
