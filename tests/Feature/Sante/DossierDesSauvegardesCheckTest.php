<?php

declare(strict_types=1);

use App\Support\Sante\DossierDesSauvegardesCheck;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process as Processus;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Facades\Health;
use Symfony\Component\Process\Exception\ProcessTimedOutException as DelaiDepasse;
use Symfony\Component\Process\Process;
use Tests\Support\PartageEndormi;

/*
 * Le dossier de l'hôte monté pour les sauvegardes n'était pas inscriptible par
 * le conteneur (#1812) :
 * la sauvegarde manuelle a échoué sur « Unable to create a directory », la
 * nocturne au même endroit, et la page « Santé » ne le disait qu'à travers
 * « Backups », vingt-six heures plus tard et sans dire pourquoi. Le contrôle
 * et la vérification du démarrage écrivent vraiment, parce que
 * `is_writable()` répond côté client sur un partage réseau.
 */

/**
 * Un dossier jetable sous storage, que le test efface.
 */
function sauvegardesDossierJetable(): string
{
    $dossier = storage_path('framework/testing/sauvegardes-'.uniqid());
    File::ensureDirectoryExists($dossier);

    return $dossier;
}

/**
 * Un chemin que personne ne peut créer, pas même root : son parent est un
 * fichier ordinaire. Un `chmod 0555` ne suffirait pas, la suite tourne en
 * root dans la CI comme dans le conteneur de test.
 */
function sauvegardesRacineSousUnFichier(): string
{
    $fichier = tempnam(sys_get_temp_dir(), 'sauvegardes-');
    assert(is_string($fichier));

    return $fichier.'/sauvegardes';
}

/**
 * L'utilisateur qui fait tourner la suite, comme le contrôle le nomme.
 */
function sauvegardesUidCourant(): string
{
    return function_exists('posix_geteuid') ? 'uid '.posix_geteuid() : "l'utilisateur du conteneur";
}

/**
 * Le vrai script du démarrage, lancé sur le dossier donné, avec son délai
 * et son PATH quand le test les donne.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environnement
 */
function sauvegardesVerificationDuDemarrage(string $dossier, array $arguments = [], array $environnement = []): Process
{
    $processus = new Process(['bash', base_path('docker/verifier-sauvegardes.sh'), $dossier, ...$arguments], base_path(), $environnement);
    $processus->setTimeout(60);
    $processus->run();

    return $processus;
}

/**
 * Le contrôle « Backups » tel que le provider l'enregistre.
 */
function sauvegardesControleDesArchives(): Check
{
    $controle = Health::registeredChecks()->first(fn (Check $check): bool => $check->getName() === 'Backups');
    assert($controle instanceof Check);

    return $controle;
}

it('met le dossier des sauvegardes au vert quand le conteneur y écrit, sans y laisser de sonde', function (): void {
    // Construit avant que la racine ne change : le contrôle la lit à chaque passage.
    $controle = DossierDesSauvegardesCheck::new();
    $dossier = sauvegardesDossierJetable();
    Config::set('filesystems.disks.sauvegardes.root', $dossier);

    try {
        $resultat = $controle->run();

        expect($resultat->status->value)->toBe('ok', $resultat->notificationMessage)
            ->and($resultat->shortSummary)->toBe('Inscriptible')
            ->and($resultat->meta['dossier'])->toBe($dossier)
            ->and(File::allFiles($dossier, hidden: true))->toBe([]);
    } finally {
        File::deleteDirectory($dossier);
    }
});

it('crée la racine du disque qui manque, comme la sauvegarde le ferait', function (): void {
    $parent = sauvegardesDossierJetable();
    $dossier = $parent.'/sauvegardes';
    Config::set('filesystems.disks.sauvegardes.root', $dossier);

    try {
        expect(DossierDesSauvegardesCheck::new()->run()->status->value)->toBe('ok')
            ->and($dossier)->toBeDirectory();
    } finally {
        File::deleteDirectory($parent);
    }
});

it('met le dossier des sauvegardes au rouge quand le conteneur ne peut pas y écrire, et dit quoi faire', function (): void {
    $racine = sauvegardesRacineSousUnFichier();
    Config::set('filesystems.disks.sauvegardes.root', $racine);

    try {
        $resultat = DossierDesSauvegardesCheck::new()->run();

        expect($resultat->status->value)->toBe('failed')
            ->and($resultat->shortSummary)->toBe('Non inscriptible')
            ->and($resultat->notificationMessage)
            ->toContain($racine)
            ->toContain("n'est pas inscriptible")
            ->toContain(sauvegardesUidCourant())
            ->toContain("donner le dossier BACKUP_HOST_PATH à l'uid 33");
    } finally {
        @unlink(dirname($racine));
    }
});

it('laisse démarrer le conteneur sans un mot quand le dossier des sauvegardes est inscriptible', function (): void {
    $dossier = sauvegardesDossierJetable();

    try {
        $processus = sauvegardesVerificationDuDemarrage($dossier);

        expect($processus->getExitCode())->toBe(0, $processus->getErrorOutput())
            ->and($processus->getErrorOutput())->toBe('')
            ->and(File::allFiles($dossier, hidden: true))->toBe([]);
    } finally {
        File::deleteDirectory($dossier);
    }
});

it('avertit au démarrage, avec le chemin et l’uid, quand le dossier des sauvegardes n’est pas inscriptible', function (): void {
    $racine = sauvegardesRacineSousUnFichier();

    try {
        $processus = sauvegardesVerificationDuDemarrage($racine);

        expect($processus->getExitCode())->not->toBe(0)
            ->and($processus->getErrorOutput())
            ->toContain($racine)
            ->toContain("n'est pas inscriptible")
            ->toContain('uid '.(function_exists('posix_geteuid') ? posix_geteuid() : ''))
            ->toContain('BACKUP_HOST_PATH');
    } finally {
        @unlink(dirname($racine));
    }
});

/*
 * Un partage réseau qui ne répond plus ne rend pas d'erreur : il fait
 * attendre, sans fin, chaque accès. Sans délai, la sonde aurait figé
 * `health:check` dans le planificateur — et les résultats de la page avec
 * lui — ou un des travailleurs d'Octane quand la page se rafraîchit.
 */
it('met le dossier des sauvegardes au rouge sans attendre quand le partage ne répond plus', function (): void {
    $dossier = sauvegardesDossierJetable();
    $endormies = PartageEndormi::commandes(['mkdir', 'touch', 'rm']);
    Config::set('filesystems.disks.sauvegardes.root', $dossier);

    try {
        $debut = microtime(true);
        $resultat = PartageEndormi::enTeteDuPath($endormies, fn (): Result => DossierDesSauvegardesCheck::new()->delai(1)->run());

        expect(microtime(true) - $debut)->toBeLessThan(10)
            ->and($resultat->status->value)->toBe('failed')
            ->and($resultat->shortSummary)->toBe('Sans réponse')
            ->and($resultat->notificationMessage)
            ->toContain($dossier)
            ->toContain("n'a pas répondu en 1 s")
            ->toContain('BACKUP_HOST_PATH');
    } finally {
        File::deleteDirectory($dossier);
        File::deleteDirectory($endormies);
    }
});

it('borne chaque accès de la sonde à dix secondes', function (): void {
    Processus::fake();
    Config::set('filesystems.disks.sauvegardes.root', '/sauvegardes');

    DossierDesSauvegardesCheck::new()->run();

    Processus::assertRanTimes(fn (PendingProcess $processus): bool => $processus->timeout === 10, 3);
});

it('dit que le partage ne répond pas quand le délai de la sonde est dépassé', function (): void {
    Processus::fake(fn () => throw new ProcessTimedOutException(
        new DelaiDepasse(new Process(['touch', '/sauvegardes/.sonde']), DelaiDepasse::TYPE_GENERAL),
        new FakeProcessResult(),
    ));
    Config::set('filesystems.disks.sauvegardes.root', '/sauvegardes');

    $resultat = DossierDesSauvegardesCheck::new()->run();

    expect($resultat->status->value)->toBe('failed')
        ->and($resultat->shortSummary)->toBe('Sans réponse')
        ->and($resultat->notificationMessage)->toContain("n'a pas répondu en 10 s");
});

/*
 * « Backups » parcourt le dossier par `glob()`, sans délai : sur un partage
 * endormi, il figerait `health:check` juste après la sonde, et le rouge de
 * la sonde ne serait jamais rangé ni envoyé.
 */
it('dit si le dossier répond, présent ou non, et pas s’il ne répond plus', function (): void {
    $endormies = PartageEndormi::commandes(['ls']);

    try {
        expect(DossierDesSauvegardesCheck::repond(sys_get_temp_dir(), 1))->toBeTrue()
            ->and(DossierDesSauvegardesCheck::repond(sys_get_temp_dir().'/absent-'.uniqid(), 1))->toBeTrue();

        $debut = microtime(true);

        expect(PartageEndormi::enTeteDuPath($endormies, fn (): bool => DossierDesSauvegardesCheck::repond(sys_get_temp_dir(), 1)))->toBeFalse()
            ->and(microtime(true) - $debut)->toBeLessThan(10);
    } finally {
        File::deleteDirectory($endormies);
    }
});

it('ne parcourt pas les archives d’un partage qui ne répond plus', function (): void {
    expect(sauvegardesControleDesArchives()->shouldRun())->toBeTrue();

    Processus::fake(fn () => throw new ProcessTimedOutException(
        new DelaiDepasse(new Process(['ls', '-A', '/sauvegardes']), DelaiDepasse::TYPE_GENERAL),
        new FakeProcessResult(),
    ));

    expect(sauvegardesControleDesArchives()->shouldRun())->toBeFalse();
});

it('rend la main au démarrage quand le partage des sauvegardes ne répond plus', function (): void {
    $dossier = sauvegardesDossierJetable();
    $endormies = PartageEndormi::commandes(['mkdir']);

    try {
        $debut = microtime(true);
        $processus = sauvegardesVerificationDuDemarrage($dossier, ['1'], ['PATH' => $endormies.':'.getenv('PATH')]);

        expect(microtime(true) - $debut)->toBeLessThan(10)
            ->and($processus->getExitCode())->toBe(1)
            ->and($processus->getErrorOutput())
            ->toContain($dossier)
            ->toContain("le partage n'a pas répondu en 1 s")
            ->toContain('BACKUP_HOST_PATH');
    } finally {
        File::deleteDirectory($dossier);
        File::deleteDirectory($endormies);
    }
});
