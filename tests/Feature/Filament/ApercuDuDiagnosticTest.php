<?php

declare(strict_types=1);

use App\Enums\PorteeDeDiagnostic;
use App\Filament\Pages\ApercuDuDiagnostic;
use App\Models\Admin;
use App\Models\ExceptionEnregistree;
use App\Models\User;
use App\Services\Diagnostic\ApercuDesDetails;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\ResumeDuDiagnostic;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\BoutonCopierLaStructure;
use Tests\Support\FilamentAdminPanel;
use Tests\Support\JobDuDiagnosticAuMessageDonne;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * « Système › Aperçu du diagnostic » (#2032) : la page montre, pour les
 * portées choisies, exactement le JSON que l'agent lirait, et le nombre de
 * caractères invisibles retirés. Elle demande la capacité `view-diagnostic` ;
 * « Copier la structure » ne copie jamais un texte `non_fiable`.
 */

/**
 * Le super administrateur, tel que le crée `AdminSeeder` : le rôle seul.
 */
function apercuDuDiagnosticSuperAdministrateur(): Admin
{
    $administrateur = Admin::factory()->create();
    $administrateur->assignRole(Role::findOrCreate('super_admin', 'admin'));
    Filament::setCurrentPanel('admin');

    return $administrateur->fresh() ?? $administrateur;
}

/**
 * Une exception récente, dont le message porte un caractère invisible.
 */
function apercuDuDiagnosticException(): ExceptionEnregistree
{
    return ExceptionEnregistree::query()->create([
        'type' => RuntimeException::class,
        'code' => '0',
        'message' => "Lecture\u{200B} impossible pour jean@example.org",
        'file' => base_path('app/Models/User.php'),
        'line' => 12,
        'trace' => [],
        'method' => 'GET',
        'path' => 'seances/1',
    ]);
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-12 10:00:00');
});

it('s’ouvre au super administrateur, et le menu la range sous Système', function (): void {
    actingAs(apercuDuDiagnosticSuperAdministrateur(), 'admin')
        ->get(ApercuDuDiagnostic::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('Aperçu du diagnostic')
        ->assertSee('Copier la structure');

    expect(ApercuDuDiagnostic::getUrl(panel: 'admin'))->toEndWith('/backoffice/apercu-du-diagnostic')
        ->and(ApercuDuDiagnostic::getNavigationGroup())->toBe('Système')
        ->and(ApercuDuDiagnostic::canAccess())->toBeTrue();
});

it('s’ouvre à qui porte la permission view-diagnostic', function (): void {
    actingAs(FilamentAdminPanel::admin([ApercuDuDiagnostic::CAPACITE]), 'admin')
        ->get(ApercuDuDiagnostic::getUrl(panel: 'admin'))
        ->assertOk();
});

it('se refuse en 403 à un administrateur sans la capacité, au chargement comme au composant', function (): void {
    $administrateur = FilamentAdminPanel::admin(['ViewAny:ExceptionEnregistree']);

    actingAs($administrateur, 'admin')
        ->get(ApercuDuDiagnostic::getUrl(panel: 'admin'))
        ->assertForbidden();

    Livewire::test(ApercuDuDiagnostic::class)->assertForbidden();

    expect(ApercuDuDiagnostic::canAccess())->toBeFalse();
});

it('renvoie un visiteur et un compte de l’application vers la connexion du panneau', function (): void {
    get(ApercuDuDiagnostic::getUrl(panel: 'admin'))->assertRedirect(route('filament.admin.auth.login'));

    actingAs(User::factory()->create(), 'web')
        ->get(ApercuDuDiagnostic::getUrl(panel: 'admin'))
        ->assertRedirect(route('filament.admin.auth.login'));
});

/**
 * La page servie à l'administrateur pour les portées et la fenêtre données,
 * comme le formulaire de la page les envoie. Un seul administrateur par cas
 * (`.ai/rules/filament.md`).
 *
 * @param  list<PorteeDeDiagnostic>|null  $portees
 */
function apercuDuDiagnosticPage(Admin $administrateur, ?array $portees = null, ?int $jours = null): string
{
    $requete = array_filter([
        'portees' => $portees === null ? null : ['', ...array_map(static fn (PorteeDeDiagnostic $portee): string => $portee->value, $portees)],
        'jours' => $jours,
    ], static fn (mixed $valeur): bool => $valeur !== null);

    return (string) actingAs($administrateur, 'admin')
        ->get(ApercuDuDiagnostic::getUrl($requete, panel: 'admin'))
        ->assertOk()
        ->getContent();
}

/**
 * Le JSON qu'une section de la page montre, décodé.
 *
 * @return array<array-key, mixed>
 */
function apercuDuDiagnosticJson(string $page, string $section): array
{
    if (preg_match('/<pre data-apercu="'.$section.'"[^>]*>(?<json>.*?)<\/pre>/s', $page, $trouve) !== 1) {
        throw new RuntimeException("La section « {$section} » est absente de la page.");
    }

    $json = json_decode(html_entity_decode($trouve['json'], ENT_QUOTES | ENT_HTML5), true);

    return is_array($json) ? $json : throw new RuntimeException("La section « {$section} » n’est pas du JSON.");
}

it('coche d’abord la santé et les exceptions, sur vingt-quatre heures, et montre leur résumé', function (): void {
    $page = apercuDuDiagnosticPage(apercuDuDiagnosticSuperAdministrateur());
    $resume = apercuDuDiagnosticJson($page, 'resume');

    expect(array_keys(is_array($resume['donnees'] ?? null) ? $resume['donnees'] : []))->toBe(['application', 'fenetre', 'portees', 'sante', 'taches_planifiees', 'versions', 'exceptions'])
        ->and(data_get($resume, 'donnees.fenetre.depuis'))->toBe('2026-10-11T10:00:00+02:00')
        ->and($page)->toMatch('/<input[^>]*name="portees\[\]"[^>]*value="sante"[^>]*checked/')
        ->and($page)->toMatch('/<input[^>]*name="portees\[\]"[^>]*value="exceptions"[^>]*checked/')
        ->and($page)->not->toMatch('/<input[^>]*name="portees\[\]"[^>]*value="files"[^>]*checked/')
        ->and($page)->toMatch('/<option value="1"\s+selected/');
});

it('montre exactement le JSON du résumé et des détails, et le nombre de caractères invisibles retirés', function (): void {
    apercuDuDiagnosticException();

    $administrateur = apercuDuDiagnosticSuperAdministrateur();
    $sans = apercuDuDiagnosticPage($administrateur, [PorteeDeDiagnostic::Exceptions]);
    $avec = apercuDuDiagnosticPage($administrateur, [PorteeDeDiagnostic::Exceptions, PorteeDeDiagnostic::TexteBrut]);
    $fenetre = FenetreDeDiagnostic::jours(1, now());
    $attendu = new ResumeDuDiagnostic()([PorteeDeDiagnostic::Exceptions, PorteeDeDiagnostic::TexteBrut], $fenetre);

    expect(apercuDuDiagnosticJson($avec, 'resume'))->toBe($attendu->enveloppe)
        ->and(apercuDuDiagnosticJson($avec, 'details'))->toBe(new ApercuDesDetails()([PorteeDeDiagnostic::Exceptions, PorteeDeDiagnostic::TexteBrut], $fenetre, $attendu->enveloppe['donnees'])->enveloppe)
        ->and(data_get(apercuDuDiagnosticJson($avec, 'details'), 'donnees.exception.non_fiable.message'))->toBe('Lecture impossible pour [courriel]')
        ->and(data_get(apercuDuDiagnosticJson($sans, 'details'), 'donnees.exception'))->not->toHaveKey('non_fiable')
        ->and($sans)->toContain('Caractères invisibles retirés : 0')
        ->and($avec)->toContain('Caractères invisibles retirés : 1')
        ->and($avec)->not->toContain('jean@example.org');
});

it('copie la structure seule, sans aucun texte non_fiable, même sous TexteBrut', function (): void {
    apercuDuDiagnosticException();

    $page = apercuDuDiagnosticPage(apercuDuDiagnosticSuperAdministrateur(), PorteeDeDiagnostic::cases());
    $structure = BoutonCopierLaStructure::structureCopiee($page);
    $texte = (string) json_encode($structure, JSON_UNESCAPED_UNICODE);

    expect(html_entity_decode($page, ENT_QUOTES | ENT_HTML5))->toContain('Lecture impossible pour [courriel]')
        ->and(array_keys($structure))->toBe(['resume', 'details'])
        ->and(data_get($structure, 'details.donnees.exception.type'))->toBe(RuntimeException::class)
        ->and(data_get($structure, 'details.donnees.exception.fichier'))->toBe('app/Models/User.php')
        ->and($texte)->not->toContain('"non_fiable"')
        ->and($texte)->not->toContain('Lecture');
});

it('ignore une portée forgée, n’en montre aucune quand rien n’est coché, et borne la fenêtre', function (): void {
    actingAs(apercuDuDiagnosticSuperAdministrateur(), 'admin');

    $forgee = apercuDuDiagnosticJson((string) get(ApercuDuDiagnostic::getUrl(['portees' => ['inventee', 'files'], 'jours' => 365], panel: 'admin'))->getContent(), 'resume');
    $vide = apercuDuDiagnosticJson((string) get(ApercuDuDiagnostic::getUrl(['portees' => [''], 'jours' => 'x'], panel: 'admin'))->getContent(), 'resume');

    expect(data_get($forgee, 'donnees.portees'))->toBe(['files'])
        ->and(data_get($forgee, 'donnees.fenetre.depuis'))->toBe('2026-09-12T10:00:00+02:00')
        ->and(array_keys(is_array($vide['donnees'] ?? null) ? $vide['donnees'] : []))->toBe(['application', 'fenetre', 'portees'])
        ->and(data_get($vide, 'donnees.fenetre.depuis'))->toBe('2026-10-11T10:00:00+02:00');
});

/*
 * Le message d'une exception peut écrire la fin d'une pile, « Next » et le
 * nom d'une classe : le diagnostic ne charge aucune classe pour savoir s'il
 * la connaît. Une classe dont le chargement échoue, comme celle qui étend
 * la classe d'une extension absente, ferait sinon tomber la commande et la
 * page tant que l'échec reste en base. L'autochargeur du test, placé devant
 * les autres, lève comme ce chargement.
 */
it('ne charge aucune classe que nomme le message d’un job échoué : la commande et la page répondent', function (): void {
    $classe = 'Diagnostic\\ClasseQuiNeSeChargePas';
    $chargeur = static function (string $demandee) use ($classe): void {
        if ($demandee === $classe) {
            throw new Error('Class "Redis" not found');
        }
    };
    dispatch(new JobDuDiagnosticAuMessageDonne("Réponse refusée\n#0 {main}\n\nNext {$classe}: refus"))->onConnection('database');
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
    $administrateur = apercuDuDiagnosticSuperAdministrateur();
    spl_autoload_register($chargeur, prepend: true);

    try {
        $code = Artisan::call('diagnostic:resume', ['--json' => true]);
        $commande = json_decode(Artisan::output(), true);
        $page = apercuDuDiagnosticPage($administrateur, [PorteeDeDiagnostic::Files, PorteeDeDiagnostic::TexteBrut]);
    } finally {
        spl_autoload_unregister($chargeur);
    }

    expect(DB::table('failed_jobs')->value('exception'))->toContain("{main}\n\nNext {$classe}: refus in ")
        ->and($code)->toBe(0)
        ->and(data_get($commande, 'donnees.files.echecs.0.exception'))->toBe(RuntimeException::class)
        ->and(data_get(apercuDuDiagnosticJson($page, 'resume'), 'donnees.files.echecs.0.exception'))->toBe(RuntimeException::class)
        ->and(data_get(apercuDuDiagnosticJson($page, 'details'), 'donnees.echec_de_job.exception'))->toBe(RuntimeException::class);
});
