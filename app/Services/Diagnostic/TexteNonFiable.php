<?php

declare(strict_types=1);

namespace App\Services\Diagnostic;

/**
 * Un texte libre tel que la base le garde, avant l'expurgation : un message
 * d'exception, un chemin, une page, une pile d'erreur navigateur.
 *
 * Une projection ne le rend que sous la clé `non_fiable` d'un détail, et
 * seulement sous la portée `TexteBrut`. `EnveloppeDeDiagnostic` l'expurge
 * (`ExpurgeLeTexte`) au budget dit ici, et refuse de l'écrire ailleurs que
 * sous `non_fiable`.
 */
final readonly class TexteNonFiable
{
    public function __construct(
        public string $brut,
        public int $budget = ExpurgeLeTexte::BUDGET_PAR_DEFAUT,
    ) {
    }
}
