<?php

declare(strict_types=1);

namespace App\Services\Diagnostic;

use JsonException;

/**
 * Une enveloppe prête à lire, et le nombre de caractères invisibles retirés
 * pour l'écrire.
 */
final readonly class EnveloppeRendue
{
    /** Toute réponse de l'accès de diagnostic fait moins de 256 Kio (#2032). */
    public const int OCTETS_AU_PLUS = 262_144;

    /**
     * @param  array{donnees: array<array-key, mixed>, suivant: int|null, tronque: bool, echantillon: bool, genere_le: string|null, note: string}  $enveloppe
     */
    public function __construct(
        public array $enveloppe,
        public int $invisiblesRetires,
    ) {
    }

    /**
     * L'enveloppe en JSON, telle que l'agent la lirait. Un encodage qui
     * échoue lève (`JsonException`) : il ne rend jamais un corps vide.
     *
     * @throws JsonException
     */
    public function json(bool $lisible = false): string
    {
        return json_encode(
            $this->enveloppe,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | ($lisible ? JSON_PRETTY_PRINT : 0),
        );
    }

    /**
     * L'enveloppe sans aucune clé `non_fiable`, à quelque profondeur que ce
     * soit : la structure seule, que le bouton « Copier la structure »
     * copie.
     *
     * @return array<array-key, mixed>
     */
    public function structure(): array
    {
        return self::sansTexteNonFiable($this->enveloppe);
    }

    /**
     * @param  array<array-key, mixed>  $valeurs
     * @return array<array-key, mixed>
     */
    private static function sansTexteNonFiable(array $valeurs): array
    {
        $structure = [];

        foreach ($valeurs as $cle => $valeur) {
            if ($cle !== EnveloppeDeDiagnostic::CLE_NON_FIABLE) {
                $structure[$cle] = is_array($valeur) ? self::sansTexteNonFiable($valeur) : $valeur;
            }
        }

        return $structure;
    }
}
