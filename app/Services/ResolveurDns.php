<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Resout un nom d'hote en adresses, et rien d'autre.
 *
 * Extrait de `PublicPushEndpoint` pour deux raisons.
 *
 * La premiere est un defaut que j'ai introduit : depuis que la regle REFUSE un
 * hote qu'elle ne sait pas resoudre (#1519), toute la suite de tests depend
 * d'Internet. Avant, un echec DNS faisait accepter, donc les tests passaient
 * quand meme ; desormais il fait refuser, et ils echouent. La dependance
 * existait deja — je l'ai rendue bloquante.
 *
 * La seconde est qu'un service se remplace : les tests lient une resolution
 * deterministe dans le conteneur et n'appellent jamais le reseau, sans que
 * chaque fichier ait a pre-remplir un cache par hote.
 */
class ResolveurDns
{
    /**
     * Toutes les adresses derriere un nom, ou un tableau vide si on ne sait pas.
     *
     * Un tableau vide veut dire « je ne sais pas ou pointe cet hote », et
     * l'appelant refuse. Voir `PublicPushEndpoint`.
     *
     * @return list<string>
     */
    public function adressesDe(string $host): array
    {
        /** @var list<string>|null $enCache */
        $enCache = Cache::get(self::cle($host));

        if ($enCache !== null) {
            return $enCache;
        }

        $records = $this->enregistrements($host);

        if ($records === false) {
            return [];
        }

        /*
         * Boucle plutot que `array_filter(array_map(...))` : un enregistrement
         * DNS est un tableau non type, donc la valeur extraite etait `mixed` et
         * le filtre sans predicat comparait de facon lache. Deux entrees de
         * baseline pour trois lignes qui se lisent mieux ecrites ainsi.
         */
        $adresses = [];

        foreach ($records as $record) {
            $adresse = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($adresse) && $adresse !== '') {
                $adresses[] = $adresse;
            }
        }

        /*
         * Seuls les succes sont gardes : un echec doit pouvoir se rattraper au
         * prochain essai plutot que d'etre grave pour une heure.
         *
         * Une heure suffit largement — il n'y a qu'une poignee de services de
         * poussee, et la resolution etait refaite a CHAQUE enregistrement
         * d'abonnement, dans le fil de la requete, sans delai d'expiration.
         */
        if ($adresses !== []) {
            Cache::put(self::cle($host), $adresses, now()->addHour());
        }

        return $adresses;
    }

    /**
     * La cle de cache d'un hote.
     */
    public static function cle(string $host): string
    {
        return 'push-endpoint:dns:'.$host;
    }

    /**
     * Le seul appel au reseau, isole pour que le reste soit verifiable.
     *
     * `dns_get_record()` ne se simule pas : une seule ligne derriere une
     * methode donne aux tests de quoi exercer le cache, l'extraction et le
     * traitement de l'echec sans jamais sortir de la machine.
     *
     * @return list<array<mixed>>|false
     */
    protected function enregistrements(string $host): array|false
    {
        return dns_get_record($host, DNS_A | DNS_AAAA);
    }
}
