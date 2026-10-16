<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\InvalidArgumentException;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Une adresse que Symfony Mime sait écrire comme destinataire (#2075).
 *
 * Les courriels de l'application passent par Symfony Mime, qui refuse une
 * adresse que la règle `email` de Laravel admet. La règle juge selon la
 * RFC 5322 (`RFCValidation` d'Egulias), qui permet un commentaire dans la
 * partie locale (« camille(salle)@example.org ») ; Mime juge par
 * `MessageIDValidation`, qui ne le permet pas, et lève une
 * `RfcComplianceException`. Un compte inscrit sous une telle adresse ne
 * recevait jamais son lien d'ouverture et restait en attente jusqu'à la
 * purge ; au profil, le lien de changement ne partait pas davantage.
 *
 * La règle demande donc à Mime lui-même : elle construit l'`Address` que
 * Laravel construit à l'envoi (`Illuminate\Mail\Message::createAddress()`)
 * et refuse ce qu'elle refuse, caractères de contrôle compris. Elle suit
 * ainsi Mime d'une version à l'autre sans recopier son jugement. Ce que Mime
 * écrit, le serveur de courriel peut encore le refuser (boîte inexistante,
 * domaine sans serveur) : la validation n'en juge pas.
 *
 * Le refus est le message de la règle `email`, celui de toute adresse mal
 * formée : il ne dit rien d'autre que la forme de l'adresse, et rien d'un
 * compte. L'adresse actuelle d'un compte, quand elle est donnée, reste admise
 * telle quelle : un compte ouvert avant la règle enregistre son nom sans
 * changer d'adresse, comme pour `AdresseEnAsciiImprimable`.
 */
final readonly class AdresseQueLaMessagerieSaitEcrire implements ValidationRule
{
    public function __construct(private ?string $adresseActuelle = null)
    {
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === $this->adresseActuelle || self::laMessagerieSaitLEcrire($value)) {
            return;
        }

        $fail('validation.email')->translate();
    }

    /**
     * Symfony Mime écrit-il cette adresse comme destinataire ?
     */
    public static function laMessagerieSaitLEcrire(string $adresse): bool
    {
        try {
            new Address($adresse);
        } catch (RfcComplianceException|InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
