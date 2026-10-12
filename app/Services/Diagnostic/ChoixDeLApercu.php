<?php

declare(strict_types=1);

namespace App\Services\Diagnostic;

use App\Enums\PorteeDeDiagnostic;

/**
 * Ce que la page « Aperçu du diagnostic » montre : les portées cochées et la
 * fenêtre, en jours, lues dans la chaîne de requête de la page.
 *
 * Sans choix, les premières portées et vingt-quatre heures. Une portée que
 * l'énumération ne connaît pas est ignorée ; une liste vide (le formulaire
 * envoyé sans case cochée) ne montre que l'application et la fenêtre.
 */
final readonly class ChoixDeLApercu
{
    /**
     * @param  list<PorteeDeDiagnostic>  $portees
     */
    public function __construct(
        public array $portees,
        public int $jours,
    ) {
    }

    /**
     * @param  array<array-key, mixed>  $requete
     */
    public static function de(array $requete): self
    {
        $portees = $requete['portees'] ?? null;

        return new self(
            match (is_array($portees)) {
                true => self::connues($portees),
                false => PorteeDeDiagnostic::PREMIERES,
            },
            ValeurLue::entier($requete['jours'] ?? null) ?? 1,
        );
    }

    /**
     * @param  array<array-key, mixed>  $valeurs
     * @return list<PorteeDeDiagnostic>
     */
    private static function connues(array $valeurs): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $valeur): ?PorteeDeDiagnostic => PorteeDeDiagnostic::tryFrom(ValeurLue::texte($valeur)),
            $valeurs,
        )));
    }
}
