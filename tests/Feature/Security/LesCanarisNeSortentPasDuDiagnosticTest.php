<?php

declare(strict_types=1);

use App\Enums\PorteeDeDiagnostic;
use App\Filament\Pages\ApercuDuDiagnostic;
use App\Models\Admin;
use App\Models\ErreurNavigateur;
use App\Models\ExceptionEnregistree;
use App\Models\TachePlanifiee;
use App\Models\User;
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
        CanarisDuDiagnostic::ecrireUnCompteEnDouble();
    })->everyMinute()->monitorName('diagnostic-canari-sql');
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
        ->and($page)->toContain('Duplicate entry')
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
 * Le message d'une exception peut recopier un texte venu d'ailleurs, sauts
 * de ligne compris : celui-ci imite une exception enveloppante et des
 * cadres de pile qui portent le code canari. Le job échoue par
 * `queue:work`, après les autres : son échec est le premier de la liste, et
 * la page en montre le détail.
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
        ->and(CanarisDuDiagnostic::trouvesDans($commande))->toBe([])
        ->and(CanarisDuDiagnostic::trouvesDans($page))->toBe([])
        ->and(CanarisDuDiagnostic::trouvesDans((string) json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))->toBe([])
        ->and(data_get($structure, 'details.donnees.echec_de_job.pile.0.fichier'))->toStartWith('vendor/laravel/framework/');
});
