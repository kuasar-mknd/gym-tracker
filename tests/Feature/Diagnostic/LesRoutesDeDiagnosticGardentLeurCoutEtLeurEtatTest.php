<?php

declare(strict_types=1);

use App\Enums\PorteeDeDiagnostic;
use App\Http\Middleware\ExigeUnJetonDeDiagnostic;
use App\Models\Admin;
use App\Models\ErreurNavigateur;
use App\Models\JetonDeDiagnostic;
use App\Models\User;
use App\Support\AlertesDeDiagnostic;
use App\Support\JournalDuDiagnostic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Monolog\Handler\NullHandler;
use Spatie\Permission\Models\Role;
use Symfony\Component\Finder\Finder;
use Tests\Support\FilamentAdminPanel;

use function Pest\Laravel\get;

/*
 * Ce que chaque route de l'accès de diagnostic coûte et ce qu'elle garde
 * d'une requête à l'autre (#2032).
 *
 * Coût : une requête à chaque route coûte le même nombre de requêtes SQL
 * devant une ligne et devant cinquante par source (aucune lecture par
 * élément), et jamais plus que `COUT_REQUETES_AU_PLUS` (vingt : `etat` en coûte quinze, les autres routes de cinq à neuf). Les routes se lisent dans
 * `ExigeUnJetonDeDiagnostic::PORTEES` : une route nouvelle fait échouer ce
 * test tant qu'elle n'a pas son adresse ici.
 *
 * État : sous Octane, l'application démarre une fois et sert des milliers de
 * requêtes. Aucune classe du diagnostic ne garde de propriété statique ni de
 * variable statique de méthode, aucune n'est liée comme singleton, et deux
 * requêtes de jetons différents ne partagent rien.
 */
const COUT_REQUETES_AU_PLUS = 20;

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 10:00:00');
    config()->set('app.diagnostic_enabled', true);
    config()->set('health.notifications.mail.to', 'alertes@example.org');
    config()->set('logging.channels.diagnostic', ['driver' => 'monolog', 'handler' => NullHandler::class]);
    config()->set('logging.channels.diagnostic_refus', ['driver' => 'monolog', 'handler' => NullHandler::class]);
    Notification::fake();
    test()->mock(MasterSupervisorRepository::class)->shouldReceive('all')->andReturn([]);
    test()->mock(WorkloadRepository::class)->shouldReceive('get')->andReturn([['name' => 'default', 'length' => 1, 'wait' => 1, 'processes' => 1]]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Un jeton neuf aux portées données, créé par un super administrateur.
 *
 * @param  list<string>  $portees
 */
function coutUnJeton(array $portees): string
{
    $admin = FilamentAdminPanel::admin(['view-diagnostic']);
    $admin->assignRole(Role::findOrCreate(Admin::roleDuSuperAdministrateur(), 'admin'));
    $identifiant = Str::lower(Str::random(JetonDeDiagnostic::LONGUEUR_DE_L_IDENTIFIANT));
    $secret = Str::random(JetonDeDiagnostic::LONGUEUR_DU_SECRET);

    JetonDeDiagnostic::factory()->create([
        'identifiant' => $identifiant,
        'empreinte' => JetonDeDiagnostic::empreinteDe($secret),
        'portees' => $portees,
        'cree_par_admin_id' => $admin->id,
    ]);

    return 'Bearer '.JetonDeDiagnostic::composer($identifiant, $secret);
}

/**
 * Les portées d'un jeton qui les a toutes.
 *
 * @return list<string>
 */
function coutToutesLesPortees(): array
{
    return array_map(static fn (PorteeDeDiagnostic $portee): string => $portee->value, PorteeDeDiagnostic::cases());
}

/**
 * Sème `$combien` lignes distinctes dans chaque source, la première fois que
 * la fonction est appelée avec un numéro de départ.
 *
 * @return array{exception: int, tache: int}
 */
function coutSemer(int $depart, int $combien): array
{
    $compte = User::factory()->create();
    $premiere = 0;
    $tache = 0;

    foreach (range($depart, $depart + $combien - 1) as $numero) {
        $identifiant = (int) DB::table('filament_exceptions_table')->insertGetId([
            'type' => RuntimeException::class, 'code' => '0', 'message' => 'boum '.$numero, 'file' => base_path('app/Models/User.php'), 'line' => $numero,
            'trace' => '[]', 'method' => 'GET', 'path' => '/seances/'.$numero, 'created_at' => now()->subMinutes(5), 'updated_at' => now(),
        ]);
        $premiere = $premiere === 0 ? $identifiant : $premiere;
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'file-'.$numero, 'payload' => '{"displayName":"App\\\\Jobs\\\\EnvoiDuCourrier"}',
            'exception' => "RuntimeException: boum in /app/x.php:{$numero}\nStack trace:\n#0 {main}", 'failed_at' => now()->subHours(2),
        ]);
        DB::table('erreurs_navigateur')->insert([
            'user_id' => $compte->id, 'type' => 'error', 'message' => 'boum', 'ligne' => $numero, 'url' => 'https://gym.example.org/seances/'.$numero,
            'agent' => 'Mozilla/5.0 Firefox/130.0', 'empreinte' => md5('erreur-'.$numero), 'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3),
        ]);
        $identifiantDeTache = (int) DB::table('monitored_scheduled_tasks')->insertGetId([
            'name' => 'tache:'.$numero, 'type' => 'command', 'cron_expression' => '30 2 * * *', 'grace_time_in_minutes' => 10,
            'last_started_at' => now()->subHours(7), 'last_finished_at' => now()->subHours(7), 'last_failed_at' => now()->subHours(3), 'created_at' => now()->subDays(40), 'updated_at' => now(),
        ]);
        $tache = $tache === 0 ? $identifiantDeTache : $tache;
        DB::table('monitored_scheduled_task_log_items')->insert(array_fill(0, 2, [
            'monitored_scheduled_task_id' => $identifiantDeTache, 'type' => 'failed', 'meta' => '{"runtime":1,"exit_code":1,"failure_message":"boum"}',
            'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3),
        ]));
    }

    return ['exception' => $premiere, 'tache' => $tache];
}

/**
 * Ajoute `$combien` occurrences au premier groupe d'exceptions, à la première
 * tâche (des échecs) et au premier groupe d'erreurs navigateur : les routes de
 * détail lisent ces lignes-là, que `coutSemer()` ne multiplie pas.
 *
 * @param  array{exception: int, tache: int}  $ids
 */
function coutAjouterDesOccurrences(array $ids, int $combien): void
{
    $compte = User::factory()->create();

    foreach (range(1, $combien) as $numero) {
        DB::table('filament_exceptions_table')->insert([
            'type' => RuntimeException::class, 'code' => '0', 'message' => 'boum', 'file' => base_path('app/Models/User.php'), 'line' => 1,
            'trace' => '[]', 'method' => 'GET', 'path' => '/seances/'.$numero, 'created_at' => now()->subMinutes(5), 'updated_at' => now(),
        ]);
        DB::table('erreurs_navigateur')->insert([
            'user_id' => $compte->id, 'type' => 'error', 'message' => 'boum', 'ligne' => 1, 'url' => 'https://gym.example.org/seances/1',
            'agent' => 'Mozilla/5.0 Firefox/130.0', 'empreinte' => md5('erreur-1'), 'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3),
        ]);
        DB::table('monitored_scheduled_task_log_items')->insert([
            'monitored_scheduled_task_id' => $ids['tache'], 'type' => 'failed', 'meta' => '{"runtime":1,"exit_code":1,"failure_message":"boum"}',
            'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3),
        ]);
    }
}

/**
 * L'adresse de chaque route de `PORTEES`, par son nom. Une route que cette
 * liste ne connaît pas fait échouer le test.
 *
 * @param  array{exception: int, tache: int}  $ids
 */
function coutAdresse(string $nom, array $ids): string
{
    return '/diagnostic/v1/'.match ($nom) {
        'diagnostic.etat' => 'etat',
        'diagnostic.sante' => 'sante',
        'diagnostic.exceptions.index' => 'exceptions',
        'diagnostic.exceptions.show' => 'exceptions/'.$ids['exception'],
        'diagnostic.exceptions.occurrences' => 'exceptions/'.$ids['exception'].'/occurrences',
        'diagnostic.taches.index' => 'taches',
        'diagnostic.taches.show' => 'taches/'.$ids['tache'],
        'diagnostic.files.index' => 'files',
        'diagnostic.erreurs-navigateur.index' => 'erreurs-navigateur',
        'diagnostic.erreurs-navigateur.show' => 'erreurs-navigateur/'.md5('erreur-1'),
        default => throw new LogicException("La route {$nom} n'a pas d'adresse dans le test de coût."),
    };
}

/**
 * Le nombre de requêtes SQL d'un appel, d'un jeton neuf et d'un cache vide :
 * l'écriture d'usage du jeton et les lectures de la garde comptent chaque
 * fois, et le cache d'un appel précédent ne cache rien de celui-ci.
 *
 * @param  array{exception: int, tache: int}  $ids
 */
function coutDeLAppel(string $nom, array $ids): int
{
    $entete = coutUnJeton(coutToutesLesPortees());
    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();

    get(coutAdresse($nom, $ids), ['Accept' => 'application/json', 'Authorization' => $entete])->assertOk();

    return count(DB::getQueryLog());
}

it('coûte le même nombre de requêtes SQL devant une ligne et devant cinquante par source, sur chaque route', function (): void {
    $ids = coutSemer(1, 1);
    // Un premier passage, non compté : le paquet des permissions charge son catalogue (deux requêtes) une fois par processus, que `Cache::flush()` ne vide pas ; le créateur et ses rôles se relisent à chaque appel.
    foreach (array_keys(ExigeUnJetonDeDiagnostic::PORTEES) as $nom) {
        coutDeLAppel($nom, $ids);
    }
    $devantUne = [];
    foreach (array_keys(ExigeUnJetonDeDiagnostic::PORTEES) as $nom) {
        $devantUne[$nom] = coutDeLAppel($nom, $ids);
    }

    coutSemer(2, 49);
    coutAjouterDesOccurrences($ids, 49);
    $devantCinquante = [];
    foreach (array_keys(ExigeUnJetonDeDiagnostic::PORTEES) as $nom) {
        $devantCinquante[$nom] = coutDeLAppel($nom, $ids);
    }

    expect($devantCinquante)->toBe($devantUne)
        ->and(array_filter($devantUne, static fn (int $requetes): bool => $requetes > COUT_REQUETES_AU_PLUS))->toBe([])
        ->and(array_filter($devantUne, static fn (int $requetes): bool => $requetes < 1))->toBe([]);
});

/**
 * Les classes que le diagnostic écrit : ses services, contrôleurs, requêtes,
 * la garde, l'audit et les alertes.
 *
 * @return list<string>
 */
function coutLesClassesDuDiagnostic(): array
{
    $dossiers = array_map(base_path(...), ['app/Services/Diagnostic', 'app/Http/Controllers/Diagnostic', 'app/Http/Requests/Diagnostic']);
    $classes = [ExigeUnJetonDeDiagnostic::class, AlertesDeDiagnostic::class, JournalDuDiagnostic::class];

    foreach (new Finder()->files()->in($dossiers)->name('*.php') as $fichier) {
        $classes[] = 'App\\'.str_replace('/', '\\', substr($fichier->getRealPath(), strlen(base_path('app/')), -4));
    }

    return $classes;
}

it('ne garde aucune propriété statique, aucune variable statique de méthode, et n’est liée comme singleton nulle part', function (): void {
    $classes = coutLesClassesDuDiagnostic();
    $statiques = [];
    $liees = [];

    foreach ($classes as $classe) {
        /** @var class-string $classe */
        $reflexion = new ReflectionClass($classe);
        $source = (string) file_get_contents((string) $reflexion->getFileName());

        foreach (array_filter($reflexion->getProperties(ReflectionProperty::IS_STATIC), static fn (ReflectionProperty $propriete): bool => $propriete->getDeclaringClass()->getName() === $classe) as $propriete) {
            $statiques[] = $classe.'::$'.$propriete->getName();
        }
        if (preg_match('/^\s*static\s+\$\w+/m', $source) === 1) {
            $statiques[] = $classe.' : variable statique';
        }
        if (app()->isShared($classe)) {
            $liees[] = $classe;
        }
    }

    expect(count($classes))->toBeGreaterThan(40)
        ->and($statiques)->toBe([])
        ->and($liees)->toBe([]);
});

it('ne laisse rien d’une requête à la suivante : un jeton sans TexteBrut après un jeton qui l’a ne voit pas de texte, un jeton sans la portée est refusé', function (): void {
    $compte = User::factory()->create();
    $empreinte = md5('partage');
    DB::table('erreurs_navigateur')->insert([
        'user_id' => $compte->id, 'type' => 'error', 'message' => 'message-du-groupe', 'url' => 'https://gym.example.org/seances/3',
        'agent' => 'Mozilla/5.0 Firefox/130.0', 'empreinte' => $empreinte, 'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3),
    ]);
    $avec = coutUnJeton(['erreurs-navigateur', 'texte-brut']);
    $sans = coutUnJeton(['erreurs-navigateur']);
    $autre = coutUnJeton(['sante']);
    $entetes = static fn (string $jeton): array => ['Accept' => 'application/json', 'Authorization' => $jeton];

    $premier = get('/diagnostic/v1/erreurs-navigateur/'.$empreinte, $entetes($avec))->assertOk();
    $deuxieme = get('/diagnostic/v1/erreurs-navigateur/'.$empreinte, $entetes($sans))->assertOk();
    $troisieme = get('/diagnostic/v1/erreurs-navigateur/'.$empreinte, $entetes($autre))->assertForbidden();
    $quatrieme = get('/diagnostic/v1/erreurs-navigateur/'.$empreinte, $entetes($avec))->assertOk();

    expect($premier->json('donnees.erreur_navigateur.non_fiable.message'))->toBe('message-du-groupe')
        ->and((string) $deuxieme->getContent())->not->toContain('"non_fiable":')
        ->and((string) $deuxieme->getContent())->not->toContain('message-du-groupe')
        ->and((string) $troisieme->getContent())->not->toContain('message-du-groupe')
        ->and($quatrieme->json('donnees.erreur_navigateur.non_fiable'))->toBe($premier->json('donnees.erreur_navigateur.non_fiable'))
        ->and(ErreurNavigateur::query()->count())->toBe(1);
});
