<?php

declare(strict_types=1);

use App\Enums\PorteeDeDiagnostic;
use App\Filament\Pages\ApercuDuDiagnostic;
use App\Models\Admin;
use App\Models\ErreurNavigateur;
use App\Models\ExceptionEnregistree;
use App\Models\TachePlanifiee;
use App\Models\User;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\ProjectionDesErreursNavigateur;
use App\Services\Diagnostic\Projections\ProjectionDesExceptions;
use App\Services\Diagnostic\Projections\ProjectionDesFiles;
use App\Services\Diagnostic\Projections\ProjectionDesTachesPlanifiees;
use App\Services\Diagnostic\ResumeDuDiagnostic;
use App\Services\Diagnostic\ValeurLue;
use App\Support\Sante\TachesPlanifieesCheck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Health\Checks\Result;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Permission\Models\Role;
use Tests\Support\BoutonCopierLaStructure;
use Tests\Support\CanarisDuDiagnostic;
use Tests\Support\JobDuDiagnosticAuMessageQuiImiteUnePile;
use Tests\Support\JobDuDiagnosticQuiEchoue;

use function Pest\Laravel\actingAs;

/*
 * Aucun canari ne sort du diagnostic (#2032), toutes portées accordées,
 * `TexteBrut` compris : ni de la commande, ni de la page, ni du bouton
 * « Copier la structure ».
 *
 * Les canaris sont semés par les vrais chemins : une requête dont
 * l'écriture est refusée (la table des exceptions), un job qui échoue
 * (`failed_jobs`, par `queue:work`), deux tâches planifiées en échec (le
 * moniteur, par `schedule:run`), deux erreurs navigateur (par leur route),
 * dont l'une porte les canaris dans le chemin de sa source, avec la forme
 * d'un fichier du build sans en être un, et le passage des contrôles de
 * santé que le magasin garde. Le test tient avec ou sans le masquage des
 * valeurs SQL à la source : la sortie ne compte pas dessus.
 *
 * Chaque texte semé passe par une sortie vérifiée. La page montre le détail
 * de la première tâche échouée par ordre de nom : celle de la requête mal
 * formée (`diagnostic-canari-base`), dont le moniteur coupe le message au
 * milieu d'une citation du serveur. Le détail de chaque élément du résumé,
 * que l'agent lira par son identifiant, est vérifié à part.
 */

/**
 * Le super administrateur, celui qui voit la page.
 */
function canarisSuperAdministrateur(): Admin
{
    $administrateur = Admin::factory()->create();
    $administrateur->assignRole(Role::findOrCreate('super_admin', 'admin'));

    return $administrateur->fresh() ?? $administrateur;
}

/**
 * Le message de la tâche à la requête mal formée, tel que le détail le rend :
 * la citation que le moniteur a coupée est fermée, puis masquée.
 */
function canarisMessageDeLaRequeteMalFormee(): string
{
    return 'SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; '
        ."check the manual that corresponds to your MySQL server version for the right syntax to use near '?'";
}

/**
 * Sème chaque canari par le chemin qui l'écrit en production.
 */
function canarisSemerParLesVraisChemins(): void
{
    Route::post('/_diagnostic-canari', static function (): never {
        CanarisDuDiagnostic::ecrireUnCompteEnDouble();

        throw new RuntimeException('L’écriture n’a pas été refusée.');
    });

    test()->withHeaders([
        'User-Agent' => 'Mozilla/5.0 ('.CanarisDuDiagnostic::NOM_PROPRE.')',
        'Authorization' => 'Bearer '.CanarisDuDiagnostic::SECRET,
        'X-Note' => CanarisDuDiagnostic::PHRASE,
    ])
        ->withServerVariables(['REMOTE_ADDR' => CanarisDuDiagnostic::ADRESSE_IP])
        ->post('/_diagnostic-canari?code='.CanarisDuDiagnostic::CODE.'&note='.rawurlencode(CanarisDuDiagnostic::PHRASE), [
            'nom' => CanarisDuDiagnostic::NOM_PROPRE,
            'courriel' => CanarisDuDiagnostic::COURRIEL,
        ])
        ->assertStatus(500);

    dispatch(new JobDuDiagnosticQuiEchoue(
        CanarisDuDiagnostic::NOM_PROPRE.' '.CanarisDuDiagnostic::CODE,
        CanarisDuDiagnostic::PHRASE.' '.CanarisDuDiagnostic::COURRIEL,
    ))->onConnection('database');
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);

    $planning = new Schedule(config()->string('app.timezone'));
    $planning->call(static function (): void {
        CanarisDuDiagnostic::ecrireUneRequeteMalFormee();
    })->everyMinute()->monitorName('diagnostic-canari-base');
    $planning->call(static function (): never {
        throw new RuntimeException(CanarisDuDiagnostic::messageReconnaissable());
    })->everyMinute()->monitorName('diagnostic-canari-courrier');
    app()->instance(Schedule::class, $planning);
    Artisan::call('schedule-monitor:sync');
    Artisan::call('schedule:run');

    actingAs(User::factory()->create())
        ->postJson(route('erreurs-navigateur.store'), [
            'type' => 'error',
            'message' => 'lecture impossible',
            'source' => 'https://gym.example.org/build/assets/'.implode('-', [
                CanarisDuDiagnostic::CODE,
                CanarisDuDiagnostic::ADRESSE_IP,
                CanarisDuDiagnostic::HOTE_DU_COURRIER,
                CanarisDuDiagnostic::SECRET,
            ]).'.js',
            'ligne' => 5,
            'colonne' => 6,
            'url' => 'https://gym.example.org/seances',
        ])
        ->assertNoContent();

    actingAs(User::factory()->create())
        ->postJson(route('erreurs-navigateur.store'), [
            'type' => 'error',
            'message' => CanarisDuDiagnostic::messageReconnaissable(),
            'source' => 'https://gym.example.org/build/assets/app-abc.js?v='.CanarisDuDiagnostic::CODE,
            'ligne' => 3,
            'colonne' => 4,
            'pile' => "TypeError: lecture impossible\n    at app-abc.js:3:4",
            'url' => 'https://gym.example.org/seances/3?note='.CanarisDuDiagnostic::PHRASE.'&nom='.CanarisDuDiagnostic::NOM_PROPRE.'#'.CanarisDuDiagnostic::CODE,
            'agent' => 'Mozilla/5.0 ('.CanarisDuDiagnostic::NOM_PROPRE.') Chrome/120',
        ])
        ->assertNoContent();

    app(ResultStore::class)->save(collect([
        Result::make()
            ->check(TachesPlanifieesCheck::new())
            ->failed(CanarisDuDiagnostic::messageReconnaissable())
            ->shortSummary(CanarisDuDiagnostic::COURRIEL)
            ->meta(['note' => CanarisDuDiagnostic::PHRASE, 'nom' => CanarisDuDiagnostic::NOM_PROPRE, 'code' => CanarisDuDiagnostic::CODE]),
    ]));
}

beforeEach(function (): void {
    Config::set('mail.mailers.smtp.host', CanarisDuDiagnostic::HOTE_DU_COURRIER);

    canarisSemerParLesVraisChemins();
});

it('sème chaque canari là où le diagnostic lit', function (): void {
    $sources = array_map(
        static fn (string $table): string => (string) json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ['filament_exceptions_table', 'failed_jobs', 'monitored_scheduled_task_log_items', 'erreurs_navigateur'],
    );

    expect(ExceptionEnregistree::query()->count())->toBeGreaterThanOrEqual(2)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(TachePlanifiee::query()->whereNotNull('last_failed_at')->count())->toBe(2)
        ->and(ErreurNavigateur::query()->count())->toBe(2)
        ->and(CanarisDuDiagnostic::trouvesDans(implode("\n", $sources)))->toEqualCanonicalizing([
            ...array_keys(CanarisDuDiagnostic::reconnaissables()),
            ...array_keys(CanarisDuDiagnostic::sansForme()),
            'adresse du compte en double',
        ]);
});

it('ne laisse aucun canari dans la commande', function (bool $json): void {
    Artisan::call('diagnostic:resume', ['--json' => $json]);

    expect(CanarisDuDiagnostic::trouvesDans(Artisan::output()))->toBe([]);
})->with(['mis en forme' => false, 'en JSON' => true]);

it('ne laisse aucun canari dans la page, toutes portées cochées, ni dans ce que copie le bouton', function (): void {
    $page = (string) actingAs(canarisSuperAdministrateur(), 'admin')
        ->get(ApercuDuDiagnostic::getUrl([
            'portees' => array_map(static fn (PorteeDeDiagnostic $portee): string => $portee->value, PorteeDeDiagnostic::cases()),
            'jours' => 30,
        ], panel: 'admin'))
        ->assertOk()
        ->getContent();
    $structure = BoutonCopierLaStructure::structureCopiee($page);

    expect(html_entity_decode($page, ENT_QUOTES | ENT_HTML5))->toContain('Envoi refusé par [hôte du courrier] ([adresse IP]) pour [courriel], clé [secret]')
        ->toContain(canarisMessageDeLaRequeteMalFormee())
        ->and($page)->toContain('Duplicate entry')
        ->and(data_get($structure, 'details.donnees.tache_planifiee.commande'))->toBe('diagnostic-canari-base')
        ->and(CanarisDuDiagnostic::trouvesDans($page))->toBe([])
        ->and(CanarisDuDiagnostic::trouvesDans((string) json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))->toBe([]);
});

it('ne laisse aucun canari dans la page servie au navigateur', function (): void {
    $reponse = actingAs(canarisSuperAdministrateur(), 'admin')
        ->get(ApercuDuDiagnostic::getUrl(panel: 'admin'))
        ->assertOk();

    expect(CanarisDuDiagnostic::trouvesDans((string) $reponse->getContent()))->toBe([]);
});

/*
 * La page ne montre que le détail du premier élément de chaque liste ;
 * l'agent lira celui de chacun par son identifiant. Le détail de chaque
 * tâche, groupe d'exceptions, échec de job et groupe d'erreurs navigateur du
 * résumé est donc lu ici, sous `TexteBrut`, et rendu par l'enveloppe.
 */
it('ne laisse aucun canari dans le détail d’aucun élément du résumé', function (): void {
    $maintenant = now();
    $fenetre = FenetreDeDiagnostic::jours(30, $maintenant);
    $resume = new ResumeDuDiagnostic()(PorteeDeDiagnostic::cases(), $fenetre)->enveloppe['donnees'];
    $details = [
        ...array_map(
            static fn (mixed $tache): ?array => new ProjectionDesTachesPlanifiees()->detail(ValeurLue::entier(data_get($tache, 'id')) ?? 0, $fenetre, true),
            (array) data_get($resume, 'taches_planifiees.taches'),
        ),
        ...array_map(
            static fn (mixed $groupe): ?array => new ProjectionDesExceptions()->detail(ValeurLue::entier(data_get($groupe, 'dernier_id')) ?? 0, $fenetre, true),
            (array) data_get($resume, 'exceptions.groupes'),
        ),
        ...array_map(
            static fn (mixed $echec): ?array => new ProjectionDesFiles()->detail(ValeurLue::texte(data_get($echec, 'dernier_uuid')), $fenetre, true),
            (array) data_get($resume, 'files.echecs'),
        ),
        ...array_map(
            static fn (mixed $groupe): ?array => new ProjectionDesErreursNavigateur()->detail(ValeurLue::texte(data_get($groupe, 'empreinte')), $fenetre, true),
            (array) data_get($resume, 'erreurs_navigateur.groupes'),
        ),
    ];

    expect($details)->toHaveCount(8)
        ->and($details)->not->toContain(null)
        ->and(CanarisDuDiagnostic::trouvesDans(new EnveloppeDeDiagnostic()(['details' => $details], $maintenant)->json()))->toBe([]);
});

/*
 * Le message de chaque tâche est comparé en entier : celui de la requête
 * mal formée, que le moniteur coupe au milieu d'une citation du serveur, et
 * celui de l'envoi refusé.
 */
it('ne laisse aucun canari dans le détail de chaque tâche planifiée en échec', function (string $tache, string $message): void {
    $maintenant = now();
    $identifiant = ValeurLue::entier(TachePlanifiee::query()->where('name', $tache)->value('id')) ?? 0;
    $detail = new ProjectionDesTachesPlanifiees()->detail($identifiant, FenetreDeDiagnostic::jours(30, $maintenant), true);
    $enveloppe = new EnveloppeDeDiagnostic()(['tache_planifiee' => $detail], $maintenant);

    expect(data_get($enveloppe->enveloppe, 'donnees.tache_planifiee.derniers_echecs.0.non_fiable.message'))->toBe($message)
        ->and(CanarisDuDiagnostic::trouvesDans($enveloppe->json()))->toBe([]);
})->with([
    'la requête mal formée' => ['diagnostic-canari-base', canarisMessageDeLaRequeteMalFormee()],
    'l’envoi refusé' => ['diagnostic-canari-courrier', 'Envoi refusé par [hôte du courrier] ([adresse IP]) pour [courriel], clé [secret]'],
]);

/*
 * Le message d'une exception peut recopier un texte venu d'ailleurs, sauts
 * de ligne compris : celui-ci imite une exception enveloppante et des
 * cadres de pile qui portent le code canari. Le job échoue par
 * `queue:work`, après les autres : son échec est le premier de la liste, et
 * la page en montre le détail. Il reste rangé sous l'exception que le job a
 * levée : le « Next » du message ne suit pas la fin d'une pile.
 */
it('ne laisse aucun canari dans la pile d’un job dont le message imite une pile', function (): void {
    dispatch(new JobDuDiagnosticAuMessageQuiImiteUnePile())->onConnection('database');
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
    Artisan::call('diagnostic:resume', ['--json' => true]);
    $commande = Artisan::output();

    $page = (string) actingAs(canarisSuperAdministrateur(), 'admin')
        ->get(ApercuDuDiagnostic::getUrl(['portees' => [PorteeDeDiagnostic::Files->value, PorteeDeDiagnostic::TexteBrut->value]], panel: 'admin'))
        ->assertOk()
        ->getContent();
    $structure = BoutonCopierLaStructure::structureCopiee($page);

    expect(DB::table('failed_jobs')->latest('id')->value('exception'))->toContain(JobDuDiagnosticAuMessageQuiImiteUnePile::message())
        ->and(data_get($structure, 'resume.donnees.files.echecs.0.job'))->toBe(JobDuDiagnosticAuMessageQuiImiteUnePile::class)
        ->and(data_get($structure, 'resume.donnees.files.echecs.0.exception'))->toBe(RuntimeException::class)
        ->and(data_get($structure, 'details.donnees.echec_de_job.exception'))->toBe(RuntimeException::class)
        ->and(CanarisDuDiagnostic::trouvesDans($commande))->toBe([])
        ->and(CanarisDuDiagnostic::trouvesDans($page))->toBe([])
        ->and(CanarisDuDiagnostic::trouvesDans((string) json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))->toBe([])
        ->and(data_get($structure, 'details.donnees.echec_de_job.pile.0.fichier'))->toStartWith('vendor/laravel/framework/');
});
