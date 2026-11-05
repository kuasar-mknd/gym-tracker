<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\AdresseCanonique;

/**
 * Écrit l'adresse reçue (`email`) sous sa forme canonique avant les règles
 * (#2168).
 *
 * Un domaine internationalisé tapé en Unicode s'écrit en ASCII
 * (`AdresseCanonique`) : la requête l'enregistre sous cette forme au lieu de
 * le refuser, et les règles (`lowercase`, `email`, `max:255`,
 * `AdresseEnAsciiImprimable`, `AdresseQueLaMessagerieSaitEcrire`) jugent
 * l'adresse que l'application écrira. Avant la validation et non après :
 * `validated()` ne voit pas ce que `passedValidation()` fusionne.
 *
 * L'adresse actuelle du compte, quand elle est donnée et reçue octet pour
 * octet, reste telle quelle : convertie, elle passerait pour un changement
 * d'adresse (`ProfileUpdateRequest::changeLAdresse()`), et un compte resté en
 * Unicode ne pourrait plus enregistrer son nom sans son mot de passe actuel.
 * Une saisie qui n'est pas une chaîne, ou que la conversion laisse, ne change
 * pas : la requête la juge comme avant.
 */
trait EcritLAdresseSousSaFormeCanonique
{
    protected function ecrireLAdresseSousSaFormeCanonique(?string $adresseActuelle = null): void
    {
        $adresse = $this->input('email');

        if (! is_string($adresse) || $adresse === $adresseActuelle) {
            return;
        }

        $forme = AdresseCanonique::de($adresse);

        if ($forme !== $adresse) {
            $this->merge(['email' => $forme]);
        }
    }
}
