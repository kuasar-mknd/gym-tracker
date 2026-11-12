<?php

declare(strict_types=1);

namespace Tests;

use App\Services\ResolveurDns;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\RedisReel;

abstract class TestCase extends BaseTestCase
{
    /**
     * Aucun test ne resout un nom d'hote sur Internet.
     *
     * `PublicPushEndpoint` refuse desormais un hote qu'elle ne sait pas
     * resoudre (#1519). Avant ce changement, un echec DNS faisait ACCEPTER,
     * donc les tests passaient meme sans reseau ; depuis, il fait refuser et
     * ils echouent. La dependance existait deja — le changement l'a rendue
     * bloquante, et deux fichiers de tests sont tombes par intermittence avant
     * que je m'en apercoive.
     *
     * Le resolveur est donc remplace pour toute la suite : tout nom rend une
     * adresse publique fixe, et les cas qui veulent l'inverse — hote
     * irresolvable, adresse privee — passent un litteral ou un domaine que la
     * RFC 2606 garantit mort, que la regle traite sans reseau.
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->app?->bind(ResolveurDns::class, fn (): ResolveurDns => new class() extends ResolveurDns
        {
            #[\Override]
            public function adressesDe(string $host): array
            {
                // `.invalid` est reserve par la RFC 2606 : il ne resout jamais,
                // et c'est ainsi qu'un test demande un hote injoignable.
                if (str_ends_with($host, '.invalid')) {
                    return [];
                }

                // `localhost` doit rester la boucle locale, sans quoi le test
                // qui verifie qu'on refuse le reseau du serveur se met a passer
                // pour la mauvaise raison.
                if ($host === 'localhost') {
                    return ['127.0.0.1'];
                }

                // 203.0.113.0/24 est reserve a la documentation par la RFC 5737 :
                // publique au sens de la regle, et injoignable en vrai.
                return ['203.0.113.7'];
            }
        });
    }

    /**
     * Un test du groupe `redis` (tests/Feature/Redis, #2151) exige un vrai
     * Redis avant ses traits.
     *
     * `setUpTraits()` passe juste après la création de l'application, avant
     * que `RefreshDatabase` ne migre la base ; un `beforeEach` de Pest ne
     * passe qu'après. Or la migration touche déjà le magasin du cache
     * (`forgetCachedPermissions()`) : contre un Redis muet, chaque test du
     * groupe tombait sur l'erreur brute de la connexion, sans dire pourquoi.
     * `RedisReel::exiger()` lit donc la configuration et le serveur ici, et
     * fait échouer le test, en le disant, avant toute migration. Les deux
     * bases se vident ensuite, migration faite, puis à la fin du test.
     *
     * @return array<class-string, class-string>
     */
    #[\Override]
    protected function setUpTraits(): array
    {
        if (! in_array(RedisReel::GROUPE, $this->groups(), true)) {
            /** @var array<class-string, class-string> */
            return parent::setUpTraits();
        }

        RedisReel::exiger();

        /** @var array<class-string, class-string> $traits */
        $traits = parent::setUpTraits();

        RedisReel::vider();
        $this->beforeApplicationDestroyed(static function (): void {
            RedisReel::vider();
        });

        return $traits;
    }
}
