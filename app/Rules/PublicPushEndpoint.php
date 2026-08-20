<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Cache;

/**
 * Rejects push endpoints that would make the server call its own network.
 *
 * The endpoint is supplied by the client and stored verbatim, then POSTed to by
 * the WebPush channel every time a notification fires. Laravel's `url` rule
 * accepts any scheme and any host, so on its own it let an authenticated user
 * point the server at loopback, private ranges, link-local — the cloud metadata
 * endpoint included. Blind SSRF: the response never comes back to the caller,
 * but the request is made.
 */
class PublicPushEndpoint implements ValidationRule
{
    /**
     * @param  Closure(string, string|null=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('L\'endpoint doit être une URL.');

            return;
        }

        $parts = parse_url($value);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            $fail('L\'endpoint doit être une URL https.');

            return;
        }

        $adresses = $this->addressesFor($parts['host']);

        /*
         * Un hote qu'on ne sait pas resoudre est REFUSE.
         *
         * Il etait accepte, au motif qu'il ne peut pas etre joint non plus et
         * qu'un incident DNS passager rejetterait des points d'acces
         * legitimes. Le raisonnement se retourne : la protection s'effaçait
         * exactement quand le reseau allait mal, et un resolveur lent ou
         * empoisonne suffisait a la faire taire (#1519).
         *
         * L'invariant tient desormais en une phrase : on accepte ce qu'on sait
         * resoudre, et dont TOUT ce qu'on resout est public. Le cout est une
         * degradation honnete — pendant une panne DNS, plus personne
         * n'enregistre d'abonnement — plutot qu'un controle qui ment.
         */
        if ($adresses === []) {
            $fail('L\'endpoint doit désigner un hôte joignable et public.');

            return;
        }

        foreach ($adresses as $address) {
            if ($this->isReserved($address)) {
                $fail('L\'endpoint doit désigner un hôte public.');

                return;
            }
        }
    }

    /**
     * Every address the host resolves to — the literal itself when it is an IP.
     *
     * A hostname is checked through DNS because `https://internal.example.com`
     * looks public and may resolve to 10.0.0.5.
     *
     * Un tableau vide veut dire « je ne sais pas ou pointe cet hote », et
     * l'appelant refuse. Voir la note dans `validate()`.
     *
     * Le resultat est mis en cache par hote : il n'y a qu'une poignee de
     * services de poussee — Google, Mozilla, Microsoft — et la resolution
     * etait refaite a CHAQUE enregistrement d'abonnement, sans delai
     * d'expiration, dans le fil de la requete. Une heure suffit largement, et
     * ne met en cache que les succes : un echec doit pouvoir se rattraper au
     * prochain essai plutot que d'etre grave pour une heure.
     *
     * @return list<string>
     */
    private function addressesFor(string $host): array
    {
        // parse_url keeps the brackets around an IPv6 literal, and FILTER_VALIDATE_IP
        // rejects them — without this, https://[::1]/ fell through to DNS, resolved
        // to nothing, and was accepted.
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        /** @var list<string>|null $enCache */
        $enCache = Cache::get(self::cle($host));

        if ($enCache !== null) {
            return $enCache;
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $adresses = array_values(array_filter(array_map(
            static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records
        )));

        if ($adresses !== []) {
            Cache::put(self::cle($host), $adresses, now()->addHour());
        }

        return $adresses;
    }

    /**
     * La cle de cache d'un hote, partagee avec les tests qui la pre-remplissent
     * pour ne pas dependre d'Internet.
     */
    public static function cle(string $host): string
    {
        return 'push-endpoint:dns:'.$host;
    }

    /**
     * Private, loopback, link-local and otherwise reserved ranges.
     */
    private function isReserved(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
