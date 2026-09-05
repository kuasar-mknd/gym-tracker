<?php

declare(strict_types=1);

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
 * Et le planificateur doit avoir quelque chose a executer, sous surveillance.
 *
 * Si la derniere tache disparaissait, le service ci-dessus deviendrait un
 * figurant que personne ne penserait a retirer.
 *
 * La verification porte sur la SOURCE plutot que sur les rappels enregistres :
 * `Event::$beforeCallbacks` est protege, et y acceder par reflexion lierait ce
 * garde a un detail interne de Laravel. La decision de surveiller une tache se
 * prend dans `routes/console.php`, c'est donc la qu'il faut regarder.
 */
it('surveille chaque tâche planifiée', function (): void {
    $source = file_get_contents(base_path('routes/console.php'));

    expect($source)->toBeString();

    $declarations = array_slice(explode('Schedule::command(', (string) $source), 1);

    expect($declarations)->not->toBeEmpty();

    $nues = [];

    foreach ($declarations as $declaration) {
        // Une declaration va jusqu'au point-virgule qui la termine.
        $instruction = explode(';', $declaration)[0];

        if (! str_contains($instruction, 'sentryMonitor(')) {
            $nues[] = trim(explode(')', $instruction)[0], "'\" ");
        }
    }

    expect($nues)->toBe([], sprintf(
        'Ces taches planifiees ne sont surveillees par rien : si elles cessent de tourner, '
        ."personne ne le saura, parce qu'une tache qui ne s'execute pas ne leve aucune erreur.\n- %s",
        implode("\n- ", $nues),
    ));
});

it('transmet aux services ce que la sauvegarde exige', function (): void {
    $services = compositionDeProduction();

    foreach (['app', 'worker', 'scheduler'] as $nom) {
        expect($services)->toHaveKey($nom);

        /** @var array{environment?: array<string, mixed>, volumes?: list<string>} $service */
        $service = $services[$nom];
        $motDePasse = $service['environment']['BACKUP_ARCHIVE_PASSWORD'] ?? null;

        expect($motDePasse)->toBeString()->toStartWith('${BACKUP_ARCHIVE_PASSWORD', sprintf(
            'Le service `%s` ne reçoit pas BACKUP_ARCHIVE_PASSWORD : posé dans Portainer, le mot de passe '
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
