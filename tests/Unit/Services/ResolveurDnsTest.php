<?php

declare(strict_types=1);

use App\Services\ResolveurDns;
use Illuminate\Support\Facades\Cache;

uses(Tests\TestCase::class);

/**
 * Le resolveur derriere `PublicPushEndpoint`.
 *
 * Ces tests comptent plus qu'il n'y parait : `TestCase` remplace ce service
 * dans TOUTE la suite pour qu'aucun test ne resolve un nom sur Internet. Sans
 * ce fichier, la vraie classe ne serait donc exercee nulle part — le cache
 * pourrait ne rien garder, ou pire garder un echec, sans qu'un seul test
 * bronche.
 *
 * Le reseau reste hors-jeu ici aussi : `enregistrements()` isole l'unique
 * appel a `dns_get_record()`, et chaque cas le remplace par ce qu'il veut
 * eprouver.
 */

/**
 * Combien de fois le reseau a ete sollicite.
 *
 * Un objet plutot qu'une propriete sur la classe anonyme : le type de retour
 * de `resolveurQuiRepond()` est `ResolveurDns`, qui ne connait pas de
 * compteur. Le passer separement le rend lisible pour l'analyse statique
 * autant que pour qui lit le test.
 */
final class AppelsAuReseau
{
    public int $total = 0;
}

/**
 * Un resolveur dont la reponse DNS est dictee.
 *
 * @param  list<array<mixed>>|false  $reponse
 */
function resolveurQuiRepond(array|false $reponse, ?AppelsAuReseau $appels = null): ResolveurDns
{
    return new class($reponse, $appels ?? new AppelsAuReseau()) extends ResolveurDns
    {
        /**
         * @param  list<array<mixed>>|false  $reponse
         */
        public function __construct(
            private readonly array|false $reponse,
            private readonly AppelsAuReseau $appels,
        ) {
        }

        #[\Override]
        protected function enregistrements(string $host): array|false
        {
            $this->appels->total++;

            return $this->reponse;
        }
    };
}

it('rend les adresses A et AAAA d’un hôte', function (): void {
    $resolveur = resolveurQuiRepond([
        ['ip' => '203.0.113.7'],
        ['ipv6' => '2001:db8::1'],
    ]);

    expect($resolveur->adressesDe('exemple.test'))->toBe(['203.0.113.7', '2001:db8::1']);
});

/*
 * Un enregistrement DNS est un tableau non type : MX, TXT ou une reponse
 * tronquee n'ont ni `ip` ni `ipv6`. Les laisser passer ferait entrer `null`
 * dans une liste que `PublicPushEndpoint` compare a des plages d'adresses.
 */
it('ignore un enregistrement sans adresse', function (): void {
    $resolveur = resolveurQuiRepond([
        ['host' => 'exemple.test', 'type' => 'MX'],
        ['ip' => '203.0.113.7'],
        ['ip' => ''],
    ]);

    expect($resolveur->adressesDe('exemple.test'))->toBe(['203.0.113.7']);
});

it('ne redemande pas au réseau ce qu’il a déjà en cache', function (): void {
    Cache::put(ResolveurDns::cle('exemple.test'), ['198.51.100.4'], now()->addHour());

    $appels = new AppelsAuReseau();
    $resolveur = resolveurQuiRepond([['ip' => '203.0.113.7']], $appels);

    expect($resolveur->adressesDe('exemple.test'))->toBe(['198.51.100.4']);
    expect($appels->total)->toBe(0);
});

it('garde une résolution réussie', function (): void {
    $resolveur = resolveurQuiRepond([['ip' => '203.0.113.7']]);

    $resolveur->adressesDe('exemple.test');

    expect(Cache::get(ResolveurDns::cle('exemple.test')))->toBe(['203.0.113.7']);
});

/*
 * L'invariant qui compte le plus.
 *
 * `PublicPushEndpoint` refuse un hote qu'elle ne sait pas resoudre. Si un
 * echec etait mis en cache, une panne DNS d'une seconde interdirait
 * l'enregistrement des abonnements pendant une heure entiere, bien apres le
 * retour du reseau — et rien ne le signalerait.
 */
it('ne garde pas un échec', function (): void {
    $resolveur = resolveurQuiRepond(false);

    expect($resolveur->adressesDe('exemple.test'))->toBe([]);
    expect(Cache::has(ResolveurDns::cle('exemple.test')))->toBeFalse();
});

it('ne garde pas une réponse vide de toute adresse', function (): void {
    $resolveur = resolveurQuiRepond([['host' => 'exemple.test', 'type' => 'MX']]);

    expect($resolveur->adressesDe('exemple.test'))->toBe([]);
    expect(Cache::has(ResolveurDns::cle('exemple.test')))->toBeFalse();
});

/*
 * La clé porte l'hôte, et un préfixe qui dit de quoi il s'agit.
 *
 * Tous les cas ci-dessus passent par `cle()` des DEUX côtés : ils vérifient que
 * le service se relit lui-même, ce qui reste vrai si la clé perd l'hôte, perd
 * son préfixe, ou les échange. Or c'est la seule clé de ce cache : sans l'hôte
 * elle confond deux domaines, sans préfixe elle entre en collision avec ce que
 * l'application range ailleurs sous le même nom.
 */
it('nomme la clé de cache par son préfixe et son hôte', function (): void {
    expect(ResolveurDns::cle('exemple.test'))->toBe('push-endpoint:dns:exemple.test');
});

/*
 * Le corollaire, vu du service : deux hôtes ne se répondent pas l'un pour
 * l'autre. Un endpoint de poussée serait alors validé contre les adresses d'un
 * autre domaine.
 */
it('ne sert pas à un hôte la résolution d’un autre', function (): void {
    resolveurQuiRepond([['ip' => '203.0.113.7']])->adressesDe('premier.test');

    $appels = new AppelsAuReseau();
    $adresses = resolveurQuiRepond([['ip' => '198.51.100.4']], $appels)->adressesDe('second.test');

    expect($adresses)->toBe(['198.51.100.4'])
        ->and($appels->total)->toBe(1);
});
