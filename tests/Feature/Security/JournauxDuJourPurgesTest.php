<?php

declare(strict_types=1);

use App\Models\TachePlanifiee;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;

/*
 * Le canal `daily` écrit, dans le volume `journaux`, un fichier par jour et
 * par conteneur (`app-2026-10-15.log`), et Monolog n'en garde que les
 * quatorze plus récents (`days`) : les quatorze derniers jours où le
 * conteneur a écrit, pas quatorze jours de calendrier, le plus ancien ne
 * partant qu'à l'ouverture du fichier d'un nouveau jour. Un conteneur qui
 * écrit peu y gardait des traces, celles d'un compte supprimé comprises, bien
 * au-delà de quatorze jours (#2053).
 *
 * Décision du propriétaire du dépôt (2026-10-12) : une purge planifiée par
 * l'âge. `journaux:purger` efface chaque nuit, pour tous les conteneurs, les
 * fichiers du jour de quatorze jours et plus, durée lue dans `days` du canal,
 * sous le moniteur des tâches ; la page de profil promet ces quatorze jours.
 * L'âge d'un fichier se lit dans son nom, comme Monolog le lit : ses lignes
 * sont celles de ce jour-là, quelle que soit sa date de modification.
 *
 * La commande est jouée ici telle que le planificateur la lance, en
 * production, sur un dossier à elle : le chemin du canal y est détourné.
 */

/**
 * La seule tâche planifiée qui lance `journaux:purger`.
 */
function journauxDuJourLaPurge(): Event
{
    $taches = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $evenement): bool => str_contains((string) $evenement->command, 'journaux:purger'),
    ));

    expect($taches)->toHaveCount(1, 'Aucune tâche planifiée ne purge les fichiers du jour : un conteneur qui écrit peu y garde ses traces bien au-delà de quatorze jours.');

    return $taches[0];
}

/**
 * La ligne du planning que le planificateur exécute, sans le binaire de PHP
 * ni `artisan`, une fois posés la production et ce chemin du canal.
 */
function journauxDuJourLaLigne(string $cheminDuCanal): string
{
    config(['logging.channels.daily.path' => $cheminDuCanal]);
    app()->detectEnvironment(static fn (): string => 'production');

    return Str::after((string) journauxDuJourLaPurge()->command, "'artisan' ");
}

/**
 * Lance la purge par sa ligne du planning, en production, sur ce dossier de
 * journaux, et rend son code de sortie.
 */
function journauxDuJourPurger(string $dossier): int
{
    return Artisan::call(journauxDuJourLaLigne($dossier.'/scheduler.log'));
}

/**
 * Un dossier de journaux neuf, vide, que le test efface en finissant.
 */
function journauxDuJourUnDossier(): string
{
    $dossier = sys_get_temp_dir().'/journaux-purges-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($dossier);

    return $dossier;
}

/**
 * Écrit une ligne dans chacun de ces fichiers du dossier.
 *
 * @param  list<string>  $fichiers
 */
function journauxDuJourSemer(string $dossier, array $fichiers): void
{
    foreach ($fichiers as $fichier) {
        File::put($dossier.'/'.$fichier, "trace d'un compte\n");
    }
}

/**
 * Ce que le dossier contient encore, fichiers et dossiers, par ordre
 * alphabétique.
 *
 * @return list<string>
 */
function journauxDuJourRestants(string $dossier): array
{
    $entrees = scandir($dossier);
    assert(is_array($entrees));

    $restants = array_values(array_diff($entrees, ['.', '..']));
    sort($restants);

    return $restants;
}

/**
 * Les mêmes noms, par ordre alphabétique, pour les comparer à ce qui reste.
 *
 * @param  list<string>  $noms
 * @return list<string>
 */
function journauxDuJourTries(array $noms): array
{
    sort($noms);

    return $noms;
}

afterEach(function (): void {
    Carbon::setTestNow();
});

/*
 * Une ligne part avec le fichier de son jour, à la purge du quatorzième jour
 * de calendrier qui suit : écrite au premier instant de ce jour, elle a alors
 * quatorze jours et l'heure de la purge, une heure de plus quand ces quatorze
 * jours couvrent le passage à l'heure d'hiver. Dans la première heure du
 * jour, la purge suit de près le minuit qui ouvre le fichier d'un nouveau
 * jour, et reste loin de l'heure que les changements d'heure sautent ou
 * répètent.
 */
it('purge chaque nuit, dans la première heure du jour, sous le moniteur des tâches', function (): void {
    $tache = journauxDuJourLaPurge();

    expect($tache->expression)->toMatch('/^\d+ 0 \* \* \*$/');

    // Le moniteur ne relit que les tâches qui ne portent pas `doNotMonitor()`.
    expect(Artisan::call('schedule-monitor:sync'))->toBe(0)
        ->and(TachePlanifiee::query()->where('name', 'journaux:purger')->exists())->toBeTrue();
});

/*
 * Trois conteneurs, chacun avec le jour même, la veille, treize, quatorze,
 * quinze et soixante jours, à côté de ce que le canal n'écrit pas. La
 * production ne transmet pas `LOG_DAILY_DAYS` : la durée y est celle de la
 * configuration, que la page de profil promet ; transmise, la variable
 * pourrait la changer sans que la page le dise.
 */
it('garde quatorze jours de calendrier pour chaque conteneur, et rien d’autre ne part, comme le promet la page de profil', function (): void {
    Carbon::setTestNow('2026-10-15 00:10:00');
    $dossier = journauxDuJourUnDossier();

    $gardes = [];
    $effaces = [];

    foreach (['app', 'worker', 'scheduler'] as $conteneur) {
        // Le jour même, en cours d'écriture ; la veille ; treize jours.
        array_push($gardes, "{$conteneur}-2026-10-15.log", "{$conteneur}-2026-10-14.log", "{$conteneur}-2026-10-02.log");
        // Quatorze, quinze et soixante jours.
        array_push($effaces, "{$conteneur}-2026-10-01.log", "{$conteneur}-2026-09-30.log", "{$conteneur}-2026-08-16.log");
    }

    // Ce que le canal `daily` n'écrit pas : le fichier du canal `single`, ceux
    // du pilote de navigateur et du serveur des parcours, une page gardée par
    // un parcours, une archive, une date impossible, une date que le nom ne
    // sépare pas par un tiret, une autre extension, un nom sans extension.
    $autres = [
        '.gitignore', 'laravel.log', 'chromedriver.log', 'serve.log', 'dusk_html_dump.html',
        'app-2026-08-16.log.gz', 'app-2026-02-30.log', 'notes_2026-08-16.log', 'notes-2026-08-16.txt', 'app-2026-08-16',
    ];

    try {
        journauxDuJourSemer($dossier, [...$gardes, ...$effaces, ...$autres]);
        // Un dossier et un lien qui portent le nom d'un fichier du jour.
        File::ensureDirectoryExists($dossier.'/archives-2026-08-16.log');
        symlink($dossier.'/chromedriver.log', $dossier.'/lien-2026-08-16.log');

        $codeDeSortie = journauxDuJourPurger($dossier);

        expect($codeDeSortie)->toBe(0, Artisan::output())
            ->and(journauxDuJourRestants($dossier))->toBe(journauxDuJourTries([...$gardes, ...$autres, 'archives-2026-08-16.log', 'lien-2026-08-16.log']))
            ->and(config('logging.channels.daily.days'))->toEqual(14)
            ->and(preg_replace('/\s+/', ' ', (string) file_get_contents(resource_path('js/Pages/Profile/Partials/DeleteUserForm.vue'))))
            ->toContain('quatorze jours dans les journaux du serveur');

        $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));
        assert(is_array($composition));

        foreach (['app', 'worker', 'scheduler'] as $nom) {
            expect(data_get($composition, "services.{$nom}.environment"))->toBeArray()
                ->and(data_get($composition, "services.{$nom}.environment.LOG_DAILY_DAYS"))->toBeNull();
        }
    } finally {
        File::deleteDirectory($dossier);
    }
});

it('lit la durée dans `days` du canal, nombre ou texte venu de l’environnement', function (int|string $jours, array $restants): void {
    Carbon::setTestNow('2026-10-15 00:10:00');
    config(['logging.channels.daily.days' => $jours]);
    $dossier = journauxDuJourUnDossier();

    try {
        journauxDuJourSemer($dossier, ['app-2026-10-09.log', 'app-2026-10-08.log', 'app-2026-10-02.log', 'app-2026-10-01.log']);

        expect(journauxDuJourPurger($dossier))->toBe(0, Artisan::output())
            ->and(journauxDuJourRestants($dossier))->toBe($restants);
    } finally {
        File::deleteDirectory($dossier);
    }
})->with([
    'quatorze' => [14, ['app-2026-10-02.log', 'app-2026-10-08.log', 'app-2026-10-09.log']],
    'sept, en texte' => ['7', ['app-2026-10-09.log']],
]);

/*
 * Les quatorze jours sont des jours de calendrier, à l'heure de
 * l'application, comme les noms des fichiers : quand ils couvrent un
 * changement d'heure, ils durent une heure de plus ou de moins que quatorze
 * fois vingt-quatre heures, et la purge efface encore le fichier du
 * quatorzième jour, sans garder celui d'un quinzième.
 */
it('compte quatorze jours de calendrier quand ils couvrent un changement d’heure', function (string $maintenant, string $quatorzeJours, string $treizeJours): void {
    Carbon::setTestNow($maintenant);
    $dossier = journauxDuJourUnDossier();

    try {
        journauxDuJourSemer($dossier, [$quatorzeJours, $treizeJours]);

        expect(journauxDuJourPurger($dossier))->toBe(0, Artisan::output())
            ->and(journauxDuJourRestants($dossier))->toBe([$treizeJours]);
    } finally {
        File::deleteDirectory($dossier);
    }
})->with([
    'le passage à l’heure d’hiver, quatorze jours et une heure' => ['2026-11-03 00:10:00', 'app-2026-10-20.log', 'app-2026-10-21.log'],
    'le passage à l’heure d’été, quatorze jours moins une heure' => ['2027-04-11 00:10:00', 'app-2027-03-28.log', 'app-2027-03-29.log'],
]);

/*
 * Pour Monolog, `days` à zéro veut dire « sans limite ». La purge n'en tire
 * pas une limite à zéro jour, qui effacerait jusqu'au fichier en cours
 * d'écriture, ni ne se tait : elle échoue, et le moniteur des tâches met la
 * santé au rouge, puisque la page de profil promet une durée que plus rien ne
 * tient.
 */
it('échoue sans rien effacer quand `days` ne borne rien', function (mixed $jours): void {
    Carbon::setTestNow('2026-10-15 00:10:00');
    config(['logging.channels.daily.days' => $jours]);
    $dossier = journauxDuJourUnDossier();

    try {
        journauxDuJourSemer($dossier, ['app-2026-10-15.log', 'app-2026-08-16.log']);

        expect(journauxDuJourPurger($dossier))->toBe(1)
            ->and(Artisan::output())->toContain('ne borne pas')
            ->and(journauxDuJourRestants($dossier))->toBe(['app-2026-08-16.log', 'app-2026-10-15.log']);
    } finally {
        File::deleteDirectory($dossier);
    }
})->with([
    'zéro, sans limite pour Monolog' => [0],
    'une durée négative' => [-3],
    'un texte' => ['quatorze'],
    'absente' => [null],
]);

/*
 * Un fichier du jour que la purge n'arrive pas à effacer la fait échouer, et
 * elle le nomme : le moniteur des tâches met alors la santé au rouge. Les
 * tests ont tous les droits sur le dossier : le refus se simule sur le
 * système de fichiers de Laravel, par lequel la purge efface.
 */
it('échoue en nommant le fichier du jour qu’elle n’a pas pu effacer', function (): void {
    Carbon::setTestNow('2026-10-15 00:10:00');
    $dossier = journauxDuJourUnDossier();

    try {
        journauxDuJourSemer($dossier, ['app-2026-08-16.log', 'worker-2026-08-16.log']);

        File::partialMock()->shouldReceive('delete')->with($dossier.'/app-2026-08-16.log')->once()->andReturn(false);

        expect(journauxDuJourPurger($dossier))->toBe(1)
            ->and(Artisan::output())->toContain('Fichiers du jour effacés : 1,', 'restés en place : '.$dossier.'/app-2026-08-16.log')
            ->and(journauxDuJourRestants($dossier))->toBe(['app-2026-08-16.log']);
    } finally {
        File::swap(new Filesystem());
        File::deleteDirectory($dossier);
    }
});

/*
 * `glob()` rend une liste vide pour un dossier absent comme pour un dossier
 * qui ne se lit pas : la purge y voyait un dossier sans fichier du jour, et
 * réussissait sans rien effacer, le moniteur au vert. Elle lève une
 * exception, que le processus `artisan` lancé par le planificateur rapporte
 * avant de sortir en 1 : le moniteur des tâches met alors la santé au rouge.
 * Les tests ont tous les droits, et un dossier sans droit de lecture s'y lit :
 * un dossier qui manque, ou un fichier à sa place, suivent la même voie.
 */
it('échoue, par le processus que lance le planificateur, quand le dossier des journaux ne se lit pas', function (string $dossierDuCanal): void {
    $dossier = journauxDuJourUnDossier();
    $sortie = new BufferedOutput();
    $message = 'Le dossier des journaux ne se lit pas : '.$dossier.'/'.$dossierDuCanal;

    try {
        journauxDuJourSemer($dossier, ['un-fichier']);
        $ligne = journauxDuJourLaLigne($dossier.'/'.$dossierDuCanal.'/scheduler.log');
        Exceptions::fake();

        expect(app(ConsoleKernel::class)->handle(new StringInput($ligne), $sortie))->toBe(1)
            ->and($sortie->fetch())->toContain('ne se lit pas');

        Exceptions::assertReported(static fn (RuntimeException $exception): bool => $exception->getMessage() === $message);
    } finally {
        File::deleteDirectory($dossier);
    }
})->with([
    'un dossier absent' => ['absent'],
    'un fichier à la place du dossier' => ['un-fichier'],
]);

/*
 * Un conteneur qui ouvre le fichier d'un nouveau jour fait lui aussi son
 * ménage, et peut effacer un fichier entre le moment où la purge le trouve et
 * celui où elle l'efface : l'effacement échoue alors, mais le fichier est
 * parti, et la purge ne s'en plaint pas.
 */
it('compte comme effacé le fichier du jour qu’un conteneur a effacé entre-temps', function (): void {
    Carbon::setTestNow('2026-10-15 00:10:00');
    $dossier = journauxDuJourUnDossier();

    try {
        journauxDuJourSemer($dossier, ['app-2026-08-16.log']);

        File::partialMock()->shouldReceive('delete')->once()->andReturnUsing(static function (string $fichier): bool {
            unlink($fichier);

            return false;
        });

        $codeDeSortie = journauxDuJourPurger($dossier);
        $sortie = Artisan::output();

        expect($codeDeSortie)->toBe(0, $sortie)
            ->and($sortie)->toContain('Fichiers du jour effacés : 1,')
            ->and(journauxDuJourRestants($dossier))->toBe([]);
    } finally {
        File::swap(new Filesystem());
        File::deleteDirectory($dossier);
    }
});

/*
 * Le fichier du jour que le canal tient ouvert reste, et le canal continue
 * d'y écrire après la purge. Monolog date ses fichiers à l'horloge du
 * système, pas à celle de Carbon : les jours passés se comptent depuis
 * cette horloge, que le test n'arrête pas.
 */
it('laisse le canal écrire dans le fichier du jour, avant et après la purge', function (): void {
    $dossier = journauxDuJourUnDossier();
    $aujourdhui = new DateTimeImmutable();
    $fichierDuJour = 'scheduler-'.$aujourdhui->format('Y-m-d').'.log';
    $quatorzeJours = 'scheduler-'.$aujourdhui->modify('-14 days')->format('Y-m-d').'.log';

    $canal = config('logging.channels.daily');
    assert(is_array($canal));

    try {
        journauxDuJourSemer($dossier, [$quatorzeJours]);

        $journal = Log::build([...$canal, 'path' => $dossier.'/scheduler.log', 'level' => 'debug']);
        $journal->info('avant la purge');

        expect(journauxDuJourPurger($dossier))->toBe(0, Artisan::output());

        $journal->info('après la purge');

        expect(journauxDuJourRestants($dossier))->toBe([$fichierDuJour])
            ->and(File::get($dossier.'/'.$fichierDuJour))->toContain('avant la purge', 'après la purge');
    } finally {
        File::deleteDirectory($dossier);
    }
});
