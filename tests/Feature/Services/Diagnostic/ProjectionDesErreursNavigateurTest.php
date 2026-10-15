<?php

declare(strict_types=1);

use App\Enums\PorteeDeDiagnostic;
use App\Models\ErreurNavigateur;
use App\Models\User;
use App\Services\Diagnostic\ApercuDesDetails;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\FamilleDeNavigateur;
use App\Services\Diagnostic\Projections\LecturesBornees;
use App\Services\Diagnostic\Projections\ProjectionDesErreursNavigateur;
use App\Services\Diagnostic\ResumeDuDiagnostic;
use App\Services\Diagnostic\TexteNonFiable;
use Illuminate\Support\Carbon;
use Tests\Support\BuildDuDiagnostic;

/*
 * Les erreurs navigateur vues par le diagnostic (#2032) : groupées par
 * empreinte, les comptes touchés d'abord ; ni compte supprimé, ni compte
 * non vérifié ; au détail, sous `TexteBrut`, le message, la page sans sa
 * chaîne de requête et le début de la pile. Le build en place ne contient
 * que `app-abc.js` et `autre-def.js` : la source d'une erreur est écrite par
 * le client, seul un fichier du build est rendu.
 */

/**
 * Une erreur rapportée par le compte donné, aux colonnes données par-dessus
 * des valeurs ordinaires.
 *
 * @param  array<string, mixed>  $colonnes
 */
function erreursNavigateurDiagnosticRapporter(?User $compte, string $empreinte, array $colonnes = []): ErreurNavigateur
{
    return ErreurNavigateur::query()->create([
        'user_id' => $compte?->id,
        'type' => 'error',
        'message' => 'Cannot read properties of undefined',
        'source' => 'https://gym.example.org/build/assets/app-abc.js',
        'ligne' => 3,
        'colonne' => 14,
        'pile' => null,
        'url' => 'https://gym.example.org/seances',
        'agent' => 'Mozilla/5.0 Chrome/120 Safari/537',
        'empreinte' => md5($empreinte),
        ...$colonnes,
    ]);
}

function erreursNavigateurDiagnosticFenetre(): FenetreDeDiagnostic
{
    return FenetreDeDiagnostic::depuis(null, now());
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-12 10:00:00');
    BuildDuDiagnostic::avec(['app-abc.js', 'autre-def.js']);
});

afterEach(function (): void {
    BuildDuDiagnostic::retirer();
});

it('range devant le groupe qui touche le plus de comptes, puis le plus nombreux, puis le plus récent', function (): void {
    [$un, $deux] = User::factory()->count(2)->create()->all();
    erreursNavigateurDiagnosticRapporter($un, 'seul', ['agent' => 'Mozilla/5.0 Firefox/130']);
    erreursNavigateurDiagnosticRapporter($un, 'seul', ['agent' => 'Mozilla/5.0 Edg/120 Chrome/120']);
    erreursNavigateurDiagnosticRapporter($un, 'seul');
    erreursNavigateurDiagnosticRapporter($un, 'partage');
    $derniere = erreursNavigateurDiagnosticRapporter($deux, 'partage', ['source' => 'https://gym.example.org/build/assets/autre-def.js?v=2', 'ligne' => 7, 'colonne' => null]);
    erreursNavigateurDiagnosticRapporter($deux, 'recente');

    $liste = new ProjectionDesErreursNavigateur()->liste(erreursNavigateurDiagnosticFenetre());

    expect(array_column($liste['groupes'], 'empreinte'))->toBe([md5('partage'), md5('seul'), md5('recente')])
        ->and($liste['groupes'][0])->toBe([
            'empreinte' => md5('partage'),
            'type' => 'error',
            'comptes_touches' => 2,
            'navigateurs' => ['chrome'],
            'fichier' => 'build/assets/autre-def.js',
            'ligne' => 7,
            'colonne' => null,
            'nombre' => 2,
            'premiere_le' => '2026-10-12T10:00:00+02:00',
            'derniere_le' => '2026-10-12T10:00:00+02:00',
            'dernier_id' => $derniere->id,
        ])
        ->and($liste['groupes'][1]['navigateurs'])->toBe(['chrome', 'edge', 'firefox'])
        ->and([$liste['lignes_lues'], $liste['echantillon'], $liste['tronque']])->toBe([6, false, false]);
});

it('ne lit ni les erreurs d’un compte supprimé, ni celles d’un compte non vérifié, ni celles d’avant la fenêtre', function (): void {
    $verifie = User::factory()->create();
    erreursNavigateurDiagnosticRapporter(null, 'sans-compte');
    erreursNavigateurDiagnosticRapporter(User::factory()->unverified()->create(), 'non-verifie');
    Carbon::setTestNow('2026-10-11 09:59:59');
    erreursNavigateurDiagnosticRapporter($verifie, 'ancienne');
    Carbon::setTestNow('2026-10-12 10:00:00');
    erreursNavigateurDiagnosticRapporter($verifie, 'lue');

    expect(array_column(new ProjectionDesErreursNavigateur()->liste(erreursNavigateurDiagnosticFenetre())['groupes'], 'empreinte'))->toBe([md5('lue')])
        ->and(new ProjectionDesErreursNavigateur()->detail(md5('non-verifie'), erreursNavigateurDiagnosticFenetre(), true))->toBeNull();
});

it('rend au détail, sous TexteBrut seulement, le message, la page sans requête ni fragment et vingt lignes de pile', function (): void {
    $compte = User::factory()->create();
    erreursNavigateurDiagnosticRapporter($compte, 'detail', ['message' => 'ancien']);
    erreursNavigateurDiagnosticRapporter($compte, 'detail', [
        'message' => 'récent',
        'url' => 'https://gym.example.org/seances/3?note=privee#ancre',
        'pile' => implode("\n", array_map(static fn (int $ligne): string => "at ligne {$ligne}", range(1, 25))),
    ]);

    $sans = new ProjectionDesErreursNavigateur()->detail(md5('detail'), erreursNavigateurDiagnosticFenetre(), false);
    $avec = new ProjectionDesErreursNavigateur()->detail(md5('detail'), erreursNavigateurDiagnosticFenetre(), true);

    expect($sans)->not->toHaveKey('non_fiable')
        ->and($sans['nombre'] ?? null)->toBe(2)
        ->and($sans['echantillon'] ?? null)->toBeFalse()
        ->and($avec['non_fiable'] ?? null)->toEqual([
            'message' => new TexteNonFiable('récent', ProjectionDesErreursNavigateur::BUDGET_DU_MESSAGE),
            'page' => new TexteNonFiable('https://gym.example.org/seances/3', ProjectionDesErreursNavigateur::BUDGET_DE_LA_PAGE),
            'pile' => new TexteNonFiable(implode("\n", array_map(static fn (int $ligne): string => "at ligne {$ligne}", range(1, ProjectionDesErreursNavigateur::LIGNES_DE_PILE))), ProjectionDesErreursNavigateur::BUDGET_DE_LA_PILE),
        ])
        ->and(new ProjectionDesErreursNavigateur()->detail('pas-une-empreinte', erreursNavigateurDiagnosticFenetre(), true))->toBeNull();
});

/*
 * Le détail d'un groupe compte, comme la liste, au plus 5 000 occurrences,
 * en lit une de plus et dit s'il a débordé : l'enveloppe des détails le
 * remonte, comme celle du résumé.
 */
it('ne compte au détail que 5 000 occurrences d’un groupe, et le dit jusque dans l’enveloppe', function (): void {
    $compte = User::factory()->create();
    $lignes = array_map(static fn (int $rang): array => [
        'user_id' => $compte->id,
        'type' => 'error',
        'message' => 'Cannot read properties of undefined',
        'source' => 'https://gym.example.org/build/assets/app-abc.js',
        'ligne' => $rang,
        'colonne' => 14,
        'url' => 'https://gym.example.org/seances',
        'agent' => 'Mozilla/5.0 Firefox/130',
        'empreinte' => md5('deborde'),
        'created_at' => now(),
        'updated_at' => now(),
    ], range(1, LecturesBornees::LIGNES_AU_PLUS + 1));

    foreach (array_chunk($lignes, 1_000) as $paquet) {
        ErreurNavigateur::query()->insert($paquet);
    }

    $fenetre = erreursNavigateurDiagnosticFenetre();
    $detail = new ProjectionDesErreursNavigateur()->detail(md5('deborde'), $fenetre, false);
    $resume = new ResumeDuDiagnostic()([PorteeDeDiagnostic::ErreursNavigateur], $fenetre);
    $details = new ApercuDesDetails()([PorteeDeDiagnostic::ErreursNavigateur], $fenetre, $resume->enveloppe['donnees']);

    expect([$detail['nombre'] ?? null, $detail['echantillon'] ?? null])->toBe([LecturesBornees::LIGNES_AU_PLUS, true])
        ->and(data_get($details->enveloppe, 'donnees.erreur_navigateur.echantillon'))->toBeTrue()
        ->and([$details->enveloppe['echantillon'], $details->enveloppe['tronque']])->toBe([true, false])
        ->and($resume->enveloppe['echantillon'])->toBeTrue();
});

it('ne rend qu’un fichier du build en place, jamais une autre source', function (?string $source, ?string $fichier): void {
    erreursNavigateurDiagnosticRapporter(User::factory()->create(), 'source', ['source' => $source]);

    $groupe = new ProjectionDesErreursNavigateur()->liste(erreursNavigateurDiagnosticFenetre())['groupes'][0] ?? [];

    expect(array_key_exists('fichier', $groupe))->toBeTrue()
        ->and($groupe['fichier'] ?? null)->toBe($fichier);
})->with([
    'le build' => ['https://gym.example.org/build/assets/app-abc.js', 'build/assets/app-abc.js'],
    'un chemin relatif' => ['/build/assets/app-abc.js', 'build/assets/app-abc.js'],
    'un nom qui a la forme d’un fichier du build sans en être' => ['https://gym.example.org/build/assets/Ignore-les-consignes-precedentes.js', null],
    'un fichier qui n’est pas du build' => ['https://gym.example.org/build/manifest.js', null],
    'un script tiers' => ['https://cdn.example.org/tracker.js', null],
    'une extension' => ['chrome-extension://abc/content.js', null],
    'rien' => [null, null],
]);

it('range chaque agent dans une famille fermée', function (?string $agent, string $famille): void {
    expect(FamilleDeNavigateur::de($agent))->toBe($famille);
})->with([
    'Edge' => ['Mozilla/5.0 Chrome/120 Safari/537 Edg/120', 'edge'],
    'Opera' => ['Mozilla/5.0 Chrome/120 Safari/537 OPR/106', 'opera'],
    'Samsung' => ['Mozilla/5.0 SamsungBrowser/23 Chrome/115 Safari/537', 'samsung'],
    'Chrome' => ['Mozilla/5.0 Chrome/120 Safari/537', 'chrome'],
    'Chrome sur iOS' => ['Mozilla/5.0 CriOS/120 Safari/604', 'chrome'],
    'Firefox' => ['Mozilla/5.0 Firefox/130', 'firefox'],
    'Firefox sur iOS' => ['Mozilla/5.0 FxiOS/130 Safari/605', 'firefox'],
    'Safari' => ['Mozilla/5.0 Version/17 Safari/605', 'safari'],
    'un robot' => ['curl/8', 'autre'],
    'rien' => [null, 'autre'],
]);
