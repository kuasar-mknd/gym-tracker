<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Mail\Message;
use Illuminate\Translation\PotentiallyTranslatedString;
use InvalidArgumentException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Une adresse que la messagerie écrit telle quelle comme destinataire (#2075).
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
 * La règle demande donc à la messagerie elle-même : elle écrit l'adresse
 * comme l'écrit à l'envoi le canal `mail` des notifications, par lequel part
 * chaque courriel de l'application, et n'admet que l'adresse écrite telle
 * quelle. Le canal passe le destinataire dans un tableau
 * (`MailChannel::getRecipients()`) au `Message` que crée le courrielleur :
 * Laravel y refuse un saut de ligne (`ensureAddressIsSafe()`), et Mime lit
 * l'adresse par `Address::create()`, qui ôte les blancs autour, refuse ce que
 * Mime ne sait pas écrire, caractères de contrôle compris, et lit un chevron
 * (`<`) comme l'ouverture d'une forme « Nom <adresse> ». Une partie locale
 * entre guillemets qui porte un chevron, que la règle `email` admet, est
 * ainsi refusée à l'envoi ou écrite sous une autre adresse : la règle la
 * refuse. Elle suit Laravel et Mime d'une version à l'autre sans recopier leur
 * jugement. Ce que Mime écrit, le serveur de courriel peut encore le refuser
 * (boîte inexistante, domaine sans serveur) : la validation n'en juge pas.
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
     * La messagerie écrit-elle cette adresse comme destinataire, telle quelle ?
     *
     * Elle l'écrit comme le canal `mail` d'une notification : dans un tableau,
     * sur un `Message` neuf, comme le courrielleur le crée.
     */
    public static function laMessagerieSaitLEcrire(string $adresse): bool
    {
        $courriel = new Email();

        try {
            new Message($courriel)->to([$adresse]);
        } catch (InvalidArgumentException) {
            return false;
        }

        return array_map(static fn (Address $destinataire): string => $destinataire->getAddress(), $courriel->getTo()) === [$adresse];
    }
}
