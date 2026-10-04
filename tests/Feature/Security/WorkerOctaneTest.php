<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use Tests\Support\WorkerOctane;

/**
 * `Tests\Support\WorkerOctane` démarre le worker sous les variables qu'on lui
 * donne, même celles que le `.env` du processus de test a chargées.
 *
 * Le dépôt de variables de Laravel (`Env::getRepository()`) est statique et
 * immuable, mais il n'épargne que les variables définies hors de lui : une
 * variable qu'il a lui-même chargée d'un `.env` est réécrite au chargement
 * suivant, celui que fait le worker à son démarrage. Avec le `.env` de la CI,
 * copié de `.env.example`, la ligne `ADMIN_ALLOWED_IPS=` effaçait ainsi la
 * liste d'adresses que le test venait de poser : le worker répondait 404 en
 * production, et six tests échouaient en lancement séquentiel, celui que
 * `CLAUDE.md` demande. En parallèle, les variables arrivent du processus
 * parent, comptent comme extérieures, et masquaient le défaut.
 *
 * Le cas est rejoué sans dépendre du `.env` de la machine : le worker démarre
 * sous un environnement inventé, et `LoadEnvironmentVariables` lui fait lire
 * `.env.<environnement>`, que le processus de test a chargé avant lui.
 */
it('démarre le worker sous la valeur posée d’une variable que le .env du test a chargée', function (): void {
    $environnement = 'essai-worker-'.Str::lower(Str::random(12));
    $fichier = base_path('.env.'.$environnement);
    file_put_contents($fichier, "WORKER_OCTANE_ESSAI=du-fichier\n");

    try {
        Dotenv::create(Env::getRepository(), base_path(), '.env.'.$environnement)->load();
        $chargeeParLeTest = Env::get('WORKER_OCTANE_ESSAI');

        $resultat = WorkerOctane::servir(
            ['APP_ENV' => $environnement, 'WORKER_OCTANE_ESSAI' => 'posee'],
            [],
            avantDeServir: static fn (): mixed => Env::get('WORKER_OCTANE_ESSAI'),
        );
    } finally {
        unlink($fichier);
        unset($_SERVER['WORKER_OCTANE_ESSAI'], $_ENV['WORKER_OCTANE_ESSAI']);
        putenv('WORKER_OCTANE_ESSAI');
    }

    expect($chargeeParLeTest)->toBe('du-fichier')
        ->and($resultat['environnement'])->toBe($environnement)
        ->and($resultat['avant'])->toBe('posee')
        ->and($resultat['erreurs'])->toBe([]);
});
