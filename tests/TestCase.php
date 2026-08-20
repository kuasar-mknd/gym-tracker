<?php

declare(strict_types=1);

namespace Tests;

use App\Services\ResolveurDns;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
}
