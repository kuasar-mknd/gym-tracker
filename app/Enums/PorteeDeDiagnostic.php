<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ce qu'une lecture du diagnostic peut voir (#2032).
 *
 * Chaque source a sa portée. `TexteBrut` n'ouvre aucune source : il ajoute,
 * au détail seulement, le texte libre expurgé, rangé sous `non_fiable`. Sans
 * lui, une réponse ne contient que de la structure.
 */
enum PorteeDeDiagnostic: string
{
    case Sante = 'sante';
    case Exceptions = 'exceptions';
    case Files = 'files';
    case ErreursNavigateur = 'erreurs-navigateur';
    case TexteBrut = 'texte-brut';

    /**
     * Les portées qui ouvrent une source, dans l'ordre où le résumé les rend.
     *
     * @var list<self>
     */
    public const array SOURCES = [self::Sante, self::Exceptions, self::Files, self::ErreursNavigateur];

    /**
     * Les portées du premier jeton, que le propriétaire a retenues : la page
     * de l'aperçu les coche d'abord.
     *
     * @var list<self>
     */
    public const array PREMIERES = [self::Sante, self::Exceptions];
}
