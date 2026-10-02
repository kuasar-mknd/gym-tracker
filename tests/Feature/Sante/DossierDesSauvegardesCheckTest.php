<?php

declare(strict_types=1);

use App\Support\Sante\DossierDesSauvegardesCheck;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

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
 * Le vrai script du démarrage, lancé sur le dossier donné.
 */
function sauvegardesVerificationDuDemarrage(string $dossier): Process
{
    $processus = new Process(['bash', base_path('docker/verifier-sauvegardes.sh'), $dossier], base_path());
    $processus->setTimeout(60);
    $processus->run();

    return $processus;
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
