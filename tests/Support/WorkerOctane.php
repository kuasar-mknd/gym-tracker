<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Admin;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Horizon;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\CurrentApplication;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un worker Octane démarré comme la production le démarre, pour les portes
 * qu'un test ordinaire ne voit pas.
 *
 * La suite tourne en `testing` et son noyau n'oublie rien entre deux
 * requêtes : le lecteur de journaux y gardait la porte qu'Octane lui fait
 * perdre dès la deuxième requête d'un worker, et Pulse une porte de test
 * toujours ouverte. Ici, une application neuve démarre depuis
 * `bootstrap/app.php` avec les variables que la pile transmettrait, puis sert
 * chaque requête dans un clone de l'application de base, avec les écouteurs de
 * `config/octane.php` (`FlushTemporaryContainerInstances` compris), comme
 * `tests/Feature/Security/TempsDuServeurTest.php` le fait pour sa mesure.
 */
final class WorkerOctane
{
    /**
     * Démarre le worker sous les variables d'environnement données, laisse
     * `$avantDeServir` inspecter l'application de base, puis lui fait servir les
     * requêtes, chacune pour `$administrateur` connecté sur la garde du panneau
     * comme sa session le ferait.
     *
     * L'application neuve lit la base par la connexion du test, pour voir ce
     * que la transaction du test vient d'écrire. Ce que son démarrage change
     * hors d'elle (application courante, résolveur et répartiteur des modèles,
     * mode strict, rappel d'Horizon, variables d'environnement) est rendu au
     * test ensuite.
     *
     * @param  array<string, string>  $environnement
     * @param  list<Request>  $requetes
     * @param  (Closure(Application): mixed)|null  $avantDeServir
     * @return array{environnement: string, statuts: list<int>, reponses: list<Response>, erreurs: list<string>, avant: mixed}
     */
    public static function servir(array $environnement, array $requetes, ?Admin $administrateur = null, ?Closure $avantDeServir = null): array
    {
        $applicationDuTest = app();
        $strictAvant = Model::preventsLazyLoading();
        $rappelDHorizon = Horizon::$authUsing;
        $pdo = DB::connection()->getPdo();
        $client = new FakeClient($requetes);
        $worker = new FakeWorker(new ApplicationFactory(base_path()), $client);
        $precedentes = self::poserLEnvironnement($environnement);
        $environnementDuWorker = '';
        $resultatAvant = null;

        try {
            $worker->boot();
            $base = $worker->application();
            $base->make('db')->connection()->setPdo($pdo)->setReadPdo($pdo);
            $environnementDuWorker = (string) $base->environment();

            if ($administrateur instanceof Admin) {
                $base->make('events')->listen(RequestReceived::class, static function (RequestReceived $evenement) use ($administrateur): void {
                    $evenement->sandbox->make('auth')->guard('admin')->setUser($administrateur);
                });
            }

            if ($avantDeServir instanceof Closure) {
                $resultatAvant = $avantDeServir($base);
            }

            $worker->run();
        } finally {
            self::rendreLEnvironnement($precedentes);
            CurrentApplication::set($applicationDuTest);
            Model::setConnectionResolver($applicationDuTest->make('db'));
            Model::setEventDispatcher($applicationDuTest->make('events'));
            Model::shouldBeStrict($strictAvant);
            Horizon::$authUsing = $rappelDHorizon;
        }

        $reponses = [];

        foreach (is_array($client->responses) ? $client->responses : [] as $reponse) {
            if ($reponse instanceof Response) {
                $reponses[] = $reponse;
            }
        }

        $erreurs = [];

        foreach (is_array($client->errors) ? $client->errors : [] as $erreur) {
            $erreurs[] = is_string($erreur) ? $erreur : get_debug_type($erreur);
        }

        return [
            'environnement' => $environnementDuWorker,
            'statuts' => array_map(static fn (Response $reponse): int => $reponse->getStatusCode(), $reponses),
            'reponses' => $reponses,
            'erreurs' => $erreurs,
            'avant' => $resultatAvant,
        ];
    }

    /**
     * Pose des variables d'environnement comme la pile les transmettrait, et
     * rend ce qu'il y avait avant.
     *
     * @param  array<string, string>  $variables
     * @return array<string, array{serveur: mixed, env: mixed, putenv: string|false}>
     */
    private static function poserLEnvironnement(array $variables): array
    {
        $precedentes = [];

        foreach ($variables as $nom => $valeur) {
            $precedentes[$nom] = [
                'serveur' => $_SERVER[$nom] ?? null,
                'env' => $_ENV[$nom] ?? null,
                'putenv' => getenv($nom),
            ];

            $_SERVER[$nom] = $_ENV[$nom] = $valeur;
            putenv($nom.'='.$valeur);
        }

        return $precedentes;
    }

    /**
     * @param  array<string, array{serveur: mixed, env: mixed, putenv: string|false}>  $precedentes
     */
    private static function rendreLEnvironnement(array $precedentes): void
    {
        foreach ($precedentes as $nom => $avant) {
            unset($_SERVER[$nom], $_ENV[$nom]);

            if ($avant['serveur'] !== null) {
                $_SERVER[$nom] = $avant['serveur'];
            }

            if ($avant['env'] !== null) {
                $_ENV[$nom] = $avant['env'];
            }

            putenv($avant['putenv'] === false ? $nom : $nom.'='.$avant['putenv']);
        }
    }
}
