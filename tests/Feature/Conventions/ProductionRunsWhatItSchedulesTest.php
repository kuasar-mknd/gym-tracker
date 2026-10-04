<?php

declare(strict_types=1);

use Illuminate\Console\ConfirmableTrait;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;

/*
 * Une tache planifiee que rien n'execute ne se signale jamais.
 *
 * `routes/console.php` planifiait `app:remind-training` en `->daily()`, et
 * `docker-compose.prod.yml` ne declarait que app, db, redis et worker. Aucun
 * service ne lancait le planificateur : la commande n'a jamais tourne en
 * production, et le rappel n'a jamais ete envoye a personne (#1443).
 *
 * Aucun test ne pouvait le voir. La suite verifie que la commande MARCHE quand
 * on l'appelle — pas que quelque chose l'appelle. Et il n'y a aucune erreur a
 * remonter, seulement une absence. C'est le trou le plus difficile a voir du
 * depot, et il est reste ouvert precisement pour cette raison.
 *
 * Ces deux tests le ferment par les deux bouts : quelque chose planifie, et
 * quelque chose execute.
 */

/**
 * Le fichier de composition de production, analyse.
 *
 * @return array<string, mixed>
 */
function compositionDeProduction(): array
{
    $chemin = base_path('docker-compose.prod.yml');

    expect($chemin)->toBeFile();

    $compose = Yaml::parseFile($chemin);

    if (! is_array($compose) || ! isset($compose['services']) || ! is_array($compose['services'])) {
        throw new RuntimeException(
            'docker-compose.prod.yml ne declare aucun bloc `services`. Sans lui, les assertions '
            .'ci-dessous passeraient sans rien verifier.'
        );
    }

    /** @var array<string, mixed> $services */
    $services = $compose['services'];

    // Un fichier vide ou renomme rendrait les assertions ci-dessous vertes et
    // muettes, ce qui est la facon la plus courante dont une convention cesse
    // de proteger.
    expect($services)->not->toBeEmpty();

    return $services;
}

it('fait exécuter le planificateur par un service de production', function (): void {
    $commandes = [];

    foreach (compositionDeProduction() as $nom => $service) {
        if (is_array($service) && isset($service['command']) && is_string($service['command'])) {
            $commandes[$nom] = $service['command'];
        }
    }

    $planificateurs = array_filter(
        $commandes,
        static fn (string $commande): bool => str_contains($commande, 'schedule:work')
            || str_contains($commande, 'schedule:run'),
    );

    expect($planificateurs)->not->toBe([], sprintf(
        "Aucun service de production n'execute le planificateur, alors que des taches sont planifiees. "
        ."Elles ne tourneront jamais, et rien ne le dira.\nCommandes declarees :\n- %s",
        implode("\n- ", array_map(
            static fn (string $nom, string $commande): string => "{$nom} : {$commande}",
            array_keys($commandes),
            $commandes,
        )),
    ));
});

/**
 * Et le planificateur doit avoir quelque chose a executer, sous le moniteur local.
 *
 * Si la derniere tache disparaissait, le service ci-dessus deviendrait un
 * figurant que personne ne penserait a retirer.
 *
 * La verification porte sur la SOURCE plutot que sur les rappels enregistres :
 * `Event::$beforeCallbacks` est protege, et y acceder par reflexion lierait ce
 * garde a un detail interne de Laravel. La decision de surveiller une tache se
 * prend dans `routes/console.php`, c'est donc la qu'il faut regarder.
 */
it('laisse chaque tâche planifiée sous le moniteur local', function (): void {
    $source = file_get_contents(base_path('routes/console.php'));
    expect($source)->toBeString();

    $declarations = array_slice(explode('Schedule::command(', (string) $source), 1);
    expect($declarations)->not->toBeEmpty();

    $exemptees = [];

    foreach ($declarations as $declaration) {
        // Une declaration va jusqu'au point-virgule qui la termine.
        $instruction = explode(';', $declaration)[0];

        // Les deux battements et le contrôle de santé se surveillent eux-mêmes :
        // `ScheduleCheck`, `QueueCheck` et la page de santé disent quand ils
        // manquent, et trois lignes par passage, 1 728 fois par jour, ne
        // diraient rien de plus.
        if (str_contains($instruction, 'ScheduleCheckHeartbeatCommand') || str_contains($instruction, 'DispatchQueueCheckJobsCommand') || str_contains($instruction, 'RunHealthChecksCommand')) {
            continue;
        }

        if (str_contains($instruction, 'doNotMonitor(')) {
            $exemptees[] = trim(explode(')', $instruction)[0], "'\" ");
        }
    }

    expect($exemptees)->toBe([], sprintf(
        'Ces taches planifiees echappent au moniteur local : si elles cessent de tourner, '
        ."personne ne le saura, parce qu'une tache qui ne s'execute pas ne leve aucune erreur.\n- %s",
        implode("\n- ", $exemptees),
    ));
});

/*
 * Une commande qui demande confirmation en production (`ConfirmableTrait`)
 * s'annule quand le planificateur la lance, sans personne pour répondre :
 * « APPLICATION IN PRODUCTION. Command cancelled. », code 1. `activitylog:clean`
 * n'a ainsi jamais purgé le journal d'audit, et chaque passage laissait une
 * exception. Rien ne le montrait hors production, où la commande ne demande
 * rien.
 */
it('fait passer --force aux tâches planifiées qui demandent confirmation en production', function (): void {
    $commandes = Artisan::all();
    $confirmables = [];
    $sansForce = [];

    foreach (app(Schedule::class)->events() as $evenement) {
        $ligne = (string) $evenement->command;

        if (preg_match("/artisan'?\\s+([\\w:.-]+)/", $ligne, $nom) !== 1) {
            continue;
        }

        $commande = $commandes[$nom[1]] ?? null;

        if (! $commande instanceof Command || ! in_array(ConfirmableTrait::class, class_uses_recursive($commande), true)) {
            continue;
        }

        $confirmables[] = $nom[1];

        if (preg_match('/\\s--force\\b/', $ligne) !== 1) {
            $sansForce[] = $ligne;
        }
    }

    // Sans elle, la garde passerait aussi le jour où plus rien ne serait reconnu.
    expect($confirmables)->toContain('activitylog:clean')
        ->and($sansForce)->toBe([], "Ces tâches s'annuleraient en production, faute de `--force` :\n- ".implode("\n- ", $sansForce));
});

it('transmet aux services ce que la sauvegarde exige', function (): void {
    $services = compositionDeProduction();

    foreach (['app', 'worker', 'scheduler'] as $nom) {
        expect($services)->toHaveKey($nom);

        /** @var array{environment?: array<string, mixed>, volumes?: list<string>} $service */
        $service = $services[$nom];
        $motDePasse = $service['environment']['BACKUP_ARCHIVE_PASSWORD'] ?? null;

        expect($motDePasse)->toBeString()->toStartWith('${BACKUP_ARCHIVE_PASSWORD', sprintf(
            'Le service `%s` ne reçoit pas BACKUP_ARCHIVE_PASSWORD : posé dans la pile, le mot de passe '
            ."n'atteindrait jamais l'application et chaque sauvegarde serait refusée.",
            $nom,
        ));

        $montages = array_filter(
            $service['volumes'] ?? [],
            static fn (string $volume): bool => str_ends_with($volume, ':/app/storage/app/sauvegardes'),
        );

        expect($montages)->toHaveCount(1, sprintf(
            'Le service `%s` ne monte pas le dossier des sauvegardes : une archive écrite là disparaîtrait au redéploiement.',
            $nom,
        ));
    }
});

/*
 * Horizon tient dans le conteneur qui le porte. Dix processus de 128 Mo dans
 * un `worker` plafonné à 512 Mo (#1630) : le noyau tuait le superviseur
 * avant que Horizon ne recycle quoi que ce soit.
 */
it('donne à Horizon un budget mémoire qui tient dans son conteneur', function (): void {
    $services = compositionDeProduction();
    $limite = data_get($services, 'worker.deploy.resources.limits.memory');

    expect($limite)->toBeString()->toEndWith('M');
    assert(is_string($limite));

    $plafond = (int) rtrim($limite, 'M');
    $processus = config('horizon.environments.production.supervisor-1.maxProcesses');
    $parProcessus = config('horizon.defaults.supervisor-1.memory');

    expect($processus)->toBeInt()->and($parProcessus)->toBeInt();
    assert(is_int($processus) && is_int($parProcessus));

    expect($processus * $parProcessus)->toBeLessThanOrEqual($plafond - 64, sprintf(
        'Horizon peut réclamer %d × %d = %d Mo, le conteneur `worker` en autorise %d ; il faut garder 64 Mo au superviseur.',
        $processus,
        $parProcessus,
        $processus * $parProcessus,
        $plafond,
    ));
});

/*
 * Le démarrage dit la vérité sur les migrations (#1630) : ni `|| true`, qui
 * faisait passer un schéma incomplet pour un déploiement réussi, ni `--quiet`,
 * qui cachait où la série s'était arrêtée.
 */
it('ne laisse ni avaler ni taire un échec de migration au démarrage', function (): void {
    $entrypoint = (string) file_get_contents(base_path('entrypoint.sh'));

    expect($entrypoint)->toContain('php artisan migrate --force')
        ->and($entrypoint)->not->toMatch('/migrate[^\n]*\|\|\s*true/')
        ->and($entrypoint)->not->toMatch('/migrate[^\n]*--quiet/');
});

/*
 * Sur le disque de production, chaque validation coûtait 250 à 500 ms de
 * synchronisation du journal, et le journal binaire doublait chaque écriture
 * pour une réplication qui n'existe pas (#1668). Les deux options tiennent
 * dans la commande du service : les perdre, c'est retrouver la lenteur.
 */
it('fait synchroniser le journal de MySQL une fois par seconde, sans journal binaire', function (): void {
    $services = compositionDeProduction();
    $commande = data_get($services, 'db.command');
    expect($commande)->toBeString()
        ->toContain('--innodb-flush-log-at-trx-commit=2')
        ->toContain('--skip-log-bin');
});

/*
 * La pile déployée tourne avec ces réglages depuis le 03/09, vérifiés par
 * SHOW VARIABLES : le défaut de MySQL 8.4 suppose un stockage rapide
 * (io_capacity 10 000), et le disque de production synchronise une écriture en
 * 310 ms (#1668). Le dépôt ne
 * les portait pas : réaligner la pile sur ce fichier les aurait perdus.
 */
it('donne à MySQL la capacité d\'entrée-sortie de la pile déployée', function (): void {
    $commande = data_get(compositionDeProduction(), 'db.command');

    expect($commande)->toBeString()
        ->toContain('--innodb-redo-log-capacity=256M')
        ->toContain('--innodb-io-capacity=200')
        ->toContain('--innodb-io-capacity-max=1000');
    assert(is_string($commande));
    expect(str_contains($commande, '--innodb-log-file-size'))->toBeFalse('`--innodb-log-file-size` est déprécié depuis MySQL 8.0.30 au profit de `--innodb-redo-log-capacity`.');
});

/**
 * Les services qui font tourner l'image de l'application, par nom.
 *
 * @return array<string, array<string, mixed>>
 */
function compositionServicesDeLApplication(): array
{
    $services = array_filter(
        compositionDeProduction(),
        static fn (mixed $service): bool => is_array($service)
            && is_string($service['image'] ?? null)
            && str_starts_with($service['image'], 'ghcr.io/kuasar-mknd/gym-tracker'),
    );

    expect(array_keys($services))->toEqualCanonicalizing(['app', 'worker', 'scheduler']);

    /** @var array<string, array<string, mixed>> $services */
    return $services;
}

/*
 * Un conteneur garde l'image avec laquelle il a été créé : le scheduler de
 * production a tourné des semaines sur une vieille image pendant que app suivait les
 * versions (#1813). Avec `pull_policy: always`, toute mise à jour de la pile
 * retélécharge l'image et recrée ce qui a changé, pour les trois.
 */
it('fait retélécharger l\'image à chaque mise à jour, pour les trois conteneurs de l\'application', function (): void {
    $images = [];

    foreach (compositionServicesDeLApplication() as $nom => $service) {
        expect($service['pull_policy'] ?? null)->toBe('always', sprintf(
            'Le service `%s` ne retélécharge pas son image : il peut rester sur une ancienne version pendant que les autres avancent.',
            $nom,
        ));

        $images[] = is_string($service['image'] ?? null) ? $service['image'] : '';
    }

    expect(array_unique($images))->toHaveCount(1);
});

/*
 * worker et scheduler journalisaient dans un fichier de leur propre
 * conteneur, ni monté ni affiché, et app sur stderr seul (#1907). stderr seul
 * ne suffit pas : le planificateur envoie la sortie de chaque tâche dans
 * /dev/null. Il faut donc, pour les trois, la même pile de canaux avec un
 * fichier, sur un volume que le visualiseur du panneau lit.
 */
it('journalise les trois conteneurs dans docker logs et dans un fichier partagé', function (): void {
    $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));
    expect($composition)->toBeArray()->toHaveKey('volumes');
    assert(is_array($composition));
    expect($composition['volumes'])->toBeArray()->toHaveKey('journaux');

    $nomsDeFichier = [];

    foreach (compositionServicesDeLApplication() as $nom => $service) {
        $environnement = $service['environment'] ?? [];
        expect($environnement)->toBeArray();
        assert(is_array($environnement));

        expect($environnement['LOG_CHANNEL'] ?? null)->toBe('stack', "`{$nom}` ne journalise pas par la pile de canaux.")
            ->and(explode(',', is_string($environnement['LOG_STACK'] ?? null) ? $environnement['LOG_STACK'] : ''))->toContain('stderr', 'daily')
            ->and($environnement['LOG_LEVEL'] ?? null)->toBeString()->toStartWith('${LOG_LEVEL');

        expect($service['volumes'] ?? [])->toContain('journaux:/app/storage/logs');

        $nomsDeFichier[] = $environnement['LOG_DAILY_NAME'] ?? null;
    }

    expect($nomsDeFichier)->toEqualCanonicalizing(['app', 'worker', 'scheduler']);
});

/*
 * Pulse écrivait ses agrégats en base à chaque requête et chaque job : 145
 * attentes de verrou en 205 s en production, toutes sur
 * pulse_aggregates, aucune une fois coupé (#1668). Coupé par défaut, comme sur
 * la pile déployée, et réglable dans la pile.
 */
it('coupe Pulse par défaut dans les trois conteneurs de l\'application', function (): void {
    foreach (compositionServicesDeLApplication() as $nom => $service) {
        expect(data_get($service, 'environment.PULSE_ENABLED'))->toBe('${PULSE_ENABLED:-false}', sprintf(
            'Pulse n\'est pas coupé par défaut dans `%s`.',
            $nom,
        ));
    }
});

/*
 * L'en-tête `Server-Timing` (#1315) dit à un utilisateur connecté combien de
 * temps et de requêtes SQL sa page a coûté : on l'allume le temps d'une
 * mesure, jamais par oubli. Sans défaut dans la composition, une variable non
 * posée arriverait vide ; le défaut écrit ici dit `false` en toutes lettres.
 */
it('coupe Server-Timing par défaut dans les trois conteneurs de l\'application', function (): void {
    foreach (compositionServicesDeLApplication() as $nom => $service) {
        expect(data_get($service, 'environment.SERVER_TIMING_ENABLED'))->toBe('${SERVER_TIMING_ENABLED:-false}', sprintf(
            'Server-Timing n\'est pas coupé par défaut dans `%s`.',
            $nom,
        ));
    }
});

it('nomme le fichier de journal du jour d\'après LOG_DAILY_NAME', function (): void {
    $lireLeChemin = static function (): mixed {
        /** @var array{channels: array{daily: array{path: string}}} $configuration */
        $configuration = require config_path('logging.php');

        return $configuration['channels']['daily']['path'];
    };

    expect($lireLeChemin())->toBe(storage_path('logs/laravel.log'));

    $_SERVER['LOG_DAILY_NAME'] = $_ENV['LOG_DAILY_NAME'] = 'worker';

    try {
        expect($lireLeChemin())->toBe(storage_path('logs/worker.log'));
    } finally {
        unset($_SERVER['LOG_DAILY_NAME'], $_ENV['LOG_DAILY_NAME']);
    }
});

/*
 * La connexion sociale n'avait aucun moyen de s'activer en production : ses
 * identifiants n'atteignaient aucun conteneur (#1908). Ils vont à app, qui
 * seul sert les pages et les rappels ; worker et scheduler n'en ont pas
 * l'usage, et un secret de moins dans un conteneur est un secret de moins.
 */
it('transmet les identifiants de la connexion sociale à app seul', function (): void {
    $services = compositionServicesDeLApplication();
    $identifiants = [
        'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GITHUB_CLIENT_ID', 'GITHUB_CLIENT_SECRET',
        // Apple, depuis #1911 : sa clé privée surtout n'a rien à faire ailleurs.
        'APPLE_CLIENT_ID', 'APPLE_TEAM_ID', 'APPLE_KEY_ID', 'APPLE_PRIVATE_KEY', 'APPLE_CLIENT_SECRET',
    ];

    foreach ($identifiants as $nom) {
        expect(data_get($services, "app.environment.{$nom}"))->toBe("\${{$nom}:-}");

        foreach (['worker', 'scheduler'] as $service) {
            expect(data_get($services, "{$service}.environment.{$nom}"))->toBeNull();
        }
    }
});
