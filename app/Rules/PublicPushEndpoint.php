<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\ResolveurDns;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuse les points d'accès push qui feraient appeler au serveur son propre
 * réseau.
 *
 * Le point d'accès vient du client, est stocké tel quel, puis le canal WebPush
 * l'interroge en POST à chaque notification. La règle `url` de Laravel accepte
 * n'importe quel schéma et n'importe quel hôte : seule, elle laissait un
 * utilisateur authentifié pointer le serveur vers la boucle locale, les plages
 * privées, le lien-local — et le point d'accès de métadonnées du nuage. SSRF
 * aveugle : la réponse ne revient jamais à l'appelant, mais la requête, elle,
 * est bien partie.
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
     * Toutes les adresses auxquelles l'hôte se résout — l'adresse elle-même
     * quand c'en est déjà une.
     *
     * Un nom d'hôte passe par le DNS parce que `https://internal.example.com` a
     * l'air public et peut se résoudre en 10.0.0.5.
     *
     * La resolution elle-meme est deleguee a `ResolveurDns`, qui la met en
     * cache et que les tests remplacent : depuis que cette regle refuse un hote
     * qu'elle ne sait pas resoudre, la suite entiere dependait d'Internet.
     *
     * @return list<string>
     */
    private function addressesFor(string $host): array
    {
        // parse_url garde les crochets autour d'une adresse IPv6 littérale, et
        // FILTER_VALIDATE_IP les refuse — sans ceci, https://[::1]/ tombait dans
        // le DNS, ne se résolvait en rien, et passait.
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        return app(ResolveurDns::class)->adressesDe($host);
    }

    /**
     * Plages privées, boucle locale, lien-local et autres plages réservées.
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
