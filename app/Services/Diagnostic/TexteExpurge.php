<?php

declare(strict_types=1);

namespace App\Services\Diagnostic;

/**
 * Un texte expurgé, et le nombre de caractères invisibles qui en ont été
 * retirés.
 */
final readonly class TexteExpurge
{
    public function __construct(
        public string $texte,
        public int $invisiblesRetires,
    ) {
    }
}
