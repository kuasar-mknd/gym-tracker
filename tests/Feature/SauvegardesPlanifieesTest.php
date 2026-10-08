<?php

declare(strict_types=1);

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Spatie\Backup\Notifications\EventHandler;

/*
 * Les sauvegardes planifiées telles que le planificateur les lance : la ligne
 * qu'il compile, passée telle quelle à la console, sur le disque `sauvegardes`
 * détourné vers un dossier jetable. Ces lignes échouaient chaque nuit avant de
 * rien faire, refusées par la console (#2020), pendant que
 * `SauvegardeRestaurableTest` passait : il appelle la commande avec un
 * tableau, comme le panneau, et non par sa ligne.
 *
 * Aucun courriel n'en part : `backup:clean` et `backup:run` coupent les leurs
 * (`--disable-notifications`), et `config/backup.php` ne donne aucun canal aux
 * deux avis de `backup:monitor`, dont l'échec passe par le moniteur des tâches
 * et la page de santé.
 */

/**
 * Ce que la console reçoit quand le planificateur lance la commande donnée :
 * sa ligne compilée, sans le binaire de PHP ni `artisan`.
 */
function sauvegardesPlanifieesLigneDe(string $commande): string
{
    $prefixe = Application::formatCommandString('');

    $evenement = collect(app(Schedule::class)->events())
        ->first(static fn (Event $evenement): bool => str_starts_with((string) $evenement->command, $prefixe.$commande));
    assert($evenement instanceof Event, "Le planning ne lance plus `{$commande}`.");

    return substr((string) $evenement->command, strlen($prefixe));
}

/**
 * Détourne le disque `sauvegardes` vers un dossier jetable, dont il rend le
 * chemin, et retient les avis au lieu de les envoyer.
 *
 * Le dossier de travail du paquet y passe aussi : `backup:run` vide le sien
 * en commençant et l'efface en finissant, et celui de la configuration est le
 * même pour tous les processus d'une suite parallèle, où
 * `SauvegardeRestaurableTest` lui effaçait son dump (et réciproquement).
 */
function sauvegardesPlanifieesDossierJetable(): string
{
    $dossier = storage_path('framework/testing/sauvegardes-planifiees-'.uniqid());
    File::ensureDirectoryExists($dossier);
    Config::set('filesystems.disks.sauvegardes.root', $dossier);
    Config::set('backup.backup.temporary_directory', $dossier.'/.temp');
    Config::set('backup.backup.password', 'mot-de-passe-de-test');
    // Le paquet fige sa configuration dans un singleton au démarrage : on la refait lire.
    app()->forgetInstance(\Spatie\Backup\Config\Config::class);
    Notification::fake();

    return $dossier;
}

/**
 * Lance la ligne planifiée de la commande et rend son code de sortie.
 *
 * Le paquet coupe ses avis par un drapeau statique, que
 * `--disable-notifications` baisse sans jamais le relever : en production,
 * chaque tâche tourne dans son propre processus et part le drapeau levé.
 */
function sauvegardesPlanifieesLancer(string $commande): int
{
    EventHandler::enable();

    return Artisan::call(sauvegardesPlanifieesLigneDe($commande));
}

it('fait tourner la nuit des sauvegardes par les lignes du planning, sans écrire à personne', function (): void {
    $dossier = sauvegardesPlanifieesDossierJetable();

    try {
        foreach (['backup:clean', 'backup:run', 'backup:monitor'] as $commande) {
            expect(sauvegardesPlanifieesLancer($commande))->toBe(0, "`{$commande}` a échoué :\n".Artisan::output());
        }

        $nom = config('backup.backup.name');
        assert(is_string($nom));

        expect(glob($dossier.'/'.$nom.'/*.zip'))->toHaveCount(1);
        Notification::assertNothingSent();
    } finally {
        File::deleteDirectory($dossier);
        EventHandler::enable();
    }
});

it('fait échouer le contrôle planifié des sauvegardes sans archive, sans écrire de courriel', function (): void {
    $dossier = sauvegardesPlanifieesDossierJetable();

    try {
        expect(sauvegardesPlanifieesLancer('backup:monitor'))->toBe(1)
            ->and(Artisan::output())->toContain('considered unhealthy');

        Notification::assertNothingSent();
    } finally {
        File::deleteDirectory($dossier);
        EventHandler::enable();
    }
});
