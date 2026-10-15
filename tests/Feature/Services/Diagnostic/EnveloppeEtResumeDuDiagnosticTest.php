<?php

declare(strict_types=1);

use App\Enums\PorteeDeDiagnostic;
use App\Models\ErreurNavigateur;
use App\Models\ExceptionEnregistree;
use App\Models\TachePlanifiee;
use App\Models\User;
use App\Services\Diagnostic\ApercuDesDetails;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\EnveloppeRendue;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\LecturesBornees;
use App\Services\Diagnostic\ResumeDuDiagnostic;
use App\Services\Diagnostic\TexteNonFiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * L'enveloppe de toute réponse du diagnostic, la fenêtre qu'il lit, le
 * résumé des portées accordées et le détail que la page de l'aperçu montre
 * (#2032).
 */

/**
 * Les données du détail que la page montre pour les portées données.
 *
 * @param  list<PorteeDeDiagnostic>  $portees
 * @return array<array-key, mixed>
 */
function enveloppeDiagnosticDetails(array $portees, FenetreDeDiagnostic $fenetre): array
{
    return new ApercuDesDetails()($portees, $fenetre, new ResumeDuDiagnostic()($portees, $fenetre)->enveloppe['donnees'])->enveloppe['donnees'];
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-12 10:00:00');
});

it('enveloppe les données avec suivant, tronque, echantillon, genere_le et la note', function (): void {
    $rendue = new EnveloppeDeDiagnostic()(['a' => 1], now(), tronque: true, echantillon: true);

    expect($rendue->enveloppe)->toBe([
        'donnees' => ['a' => 1],
        'suivant' => null,
        'tronque' => true,
        'echantillon' => true,
        'genere_le' => '2026-10-12T10:00:00+02:00',
        'note' => EnveloppeDeDiagnostic::NOTE,
    ])->and($rendue->invisiblesRetires)->toBe(0)
        ->and(new EnveloppeDeDiagnostic()([], now())->enveloppe)->toMatchArray(['tronque' => false, 'echantillon' => false]);
});

it('retire les caractères invisibles de toute chaîne, champs structurels compris, et les compte', function (): void {
    $rendue = new EnveloppeDeDiagnostic()([
        'type' => "Runtime\u{200B}Exception",
        'liste' => ["a\u{E0041}\u{E0042}", 3, null, true],
        'non_fiable' => ['message' => new TexteNonFiable("x\u{FE0F}y pour jean@example.org", 64)],
    ], now());

    expect($rendue->enveloppe['donnees'])->toBe([
        'type' => 'RuntimeException',
        'liste' => ['a', 3, null, true],
        'non_fiable' => ['message' => 'xy pour [courriel]'],
    ])->and($rendue->invisiblesRetires)->toBe(4);
});

it('refuse d’écrire un texte non fiable ailleurs que sous non_fiable', function (): void {
    expect(static fn (): EnveloppeRendue => new EnveloppeDeDiagnostic()(['detail' => ['message' => new TexteNonFiable('x')]], now()))
        ->toThrow(new LogicException('Un texte non fiable ne s’écrit que sous « non_fiable ».'));
});

it('rend la structure sans aucune clé non_fiable, et le JSON tel que l’agent le lirait', function (): void {
    $rendue = new EnveloppeRendue([
        'donnees' => ['a' => ['non_fiable' => ['m' => 'x'], 'b' => 'é/c'], 'non_fiable' => 'y'],
        'suivant' => null,
        'tronque' => false,
        'echantillon' => false,
        'genere_le' => null,
        'note' => 'n',
    ], 0);

    expect($rendue->structure())->toBe([
        'donnees' => ['a' => ['b' => 'é/c']],
        'suivant' => null,
        'tronque' => false,
        'echantillon' => false,
        'genere_le' => null,
        'note' => 'n',
    ])
        ->and($rendue->json())->toBe('{"donnees":{"a":{"non_fiable":{"m":"x"},"b":"é/c"},"non_fiable":"y"},"suivant":null,"tronque":false,"echantillon":false,"genere_le":null,"note":"n"}')
        ->and($rendue->json(lisible: true))->toStartWith("{\n    \"donnees\": {\n");
});

it('lit vingt-quatre heures sans saisie, ramène un instant au fuseau et le borne à trente jours', function (?string $saisie, string $depuis): void {
    Config::set('app.timezone', 'Europe/Paris');
    $fenetre = FenetreDeDiagnostic::depuis($saisie, Carbon::parse('2026-10-12T08:00:00Z'));

    expect($fenetre->depuis->toIso8601String())->toBe($depuis)
        ->and($fenetre->jusqua->toIso8601String())->toBe('2026-10-12T10:00:00+02:00');
})->with([
    'sans saisie' => [null, '2026-10-11T10:00:00+02:00'],
    'une saisie vide' => ['', '2026-10-11T10:00:00+02:00'],
    'un instant en UTC' => ['2026-10-10T06:30:00Z', '2026-10-10T08:30:00+02:00'],
    'un décalage' => ['2026-10-10T06:30:00-05:00', '2026-10-10T13:30:00+02:00'],
    'trente jours pile' => ['2026-09-12T10:00:00+02:00', '2026-09-12T10:00:00+02:00'],
    'au-delà de trente jours' => ['2026-01-01', '2026-09-12T10:00:00+02:00'],
    'dans le futur' => ['2027-01-01T00:00:00Z', '2026-10-12T10:00:00+02:00'],
    'un 29 février bissextile' => ['2024-02-29', '2026-09-12T10:00:00+02:00'],
]);

it('refuse ce qui n’est pas un instant ISO 8601, ou une date qui n’existe pas', function (string $saisie): void {
    expect(static fn (): FenetreDeDiagnostic => FenetreDeDiagnostic::depuis($saisie, now()))
        ->toThrow(new InvalidArgumentException(FenetreDeDiagnostic::INSTANT_ATTENDU));
})->with([
    'hier', 'yesterday', '12/10/2026', '2026-10-11T08:00:00+02:00 ; DROP', "2026-10-11\n", '2026-13-45', '2026-10-11T25:61',
    '2026-09-31', '2026-02-29', '2026-00-10', '2026-10-00', '2026-10-11T24:00', '2026-10-11T23:59:60',
]);

it('lit les derniers jours, de un à trente', function (int $jours, string $depuis): void {
    expect(FenetreDeDiagnostic::jours($jours, now())->depuis->toIso8601String())->toBe($depuis);
})->with([
    [1, '2026-10-11T10:00:00+02:00'],
    [7, '2026-10-05T10:00:00+02:00'],
    [0, '2026-10-11T10:00:00+02:00'],
    [31, '2026-09-12T10:00:00+02:00'],
]);

it('ne rend que les sections des portées accordées, et lève les drapeaux d’une section', function (): void {
    ExceptionEnregistree::query()->create(['type' => RuntimeException::class, 'code' => '0', 'message' => 'm', 'file' => 'f', 'line' => 1, 'trace' => [], 'method' => 'GET', 'path' => '/']);
    $fenetre = FenetreDeDiagnostic::depuis(null, now());

    $exceptions = new ResumeDuDiagnostic()([PorteeDeDiagnostic::Exceptions, PorteeDeDiagnostic::TexteBrut], $fenetre)->enveloppe;
    $tout = new ResumeDuDiagnostic()(PorteeDeDiagnostic::cases(), $fenetre)->enveloppe;

    expect(array_keys($exceptions['donnees']))->toBe(['application', 'fenetre', 'portees', 'exceptions'])
        ->and($exceptions['donnees']['portees'])->toBe(['exceptions', 'texte-brut'])
        ->and($exceptions['donnees']['fenetre'])->toBe(['depuis' => '2026-10-11T10:00:00+02:00', 'jusqua' => '2026-10-12T10:00:00+02:00'])
        ->and(array_keys($tout['donnees']))->toBe(['application', 'fenetre', 'portees', 'sante', 'taches_planifiees', 'versions', 'exceptions', 'files', 'erreurs_navigateur'])
        ->and([$tout['tronque'], $tout['echantillon']])->toBe([false, false])
        ->and(json_encode($tout['donnees']))->not->toContain('non_fiable');

    $lignes = array_map(static fn (int $rang): array => ['type' => RuntimeException::class, 'code' => '0', 'message' => 'm', 'file' => 'f', 'line' => $rang, 'trace' => '[]', 'method' => 'GET', 'path' => '/', 'created_at' => now(), 'updated_at' => now()], range(1, 51));
    DB::table('filament_exceptions_table')->insert($lignes);

    expect(new ResumeDuDiagnostic()([PorteeDeDiagnostic::Exceptions], $fenetre)->enveloppe)->toMatchArray(['tronque' => true, 'echantillon' => false]);
});

it('détaille le premier élément de chaque liste, la première tâche échouée, et le texte sous TexteBrut seulement', function (): void {
    $compte = User::factory()->create();
    $exception = ExceptionEnregistree::query()->create(['type' => RuntimeException::class, 'code' => '0', 'message' => 'boum', 'file' => 'f', 'line' => 1, 'trace' => [], 'method' => 'GET', 'path' => '/']);
    DB::table('failed_jobs')->insert(['uuid' => $uuid = (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'RuntimeException: boum', 'failed_at' => now()]);
    ErreurNavigateur::query()->create(['user_id' => $compte->id, 'type' => 'error', 'message' => 'm', 'url' => '/', 'empreinte' => md5('e')]);
    TachePlanifiee::query()->create(['name' => 'a:ordinaire', 'type' => 'command', 'cron_expression' => '0 18 * * *', 'grace_time_in_minutes' => 5]);
    $echouee = TachePlanifiee::query()->create(['name' => 'b:echouee', 'type' => 'command', 'cron_expression' => '0 18 * * *', 'grace_time_in_minutes' => 5, 'last_failed_at' => now()]);
    $fenetre = FenetreDeDiagnostic::depuis(null, now());

    $sansTexte = enveloppeDiagnosticDetails(PorteeDeDiagnostic::SOURCES, $fenetre);
    $avecTexte = enveloppeDiagnosticDetails(PorteeDeDiagnostic::cases(), $fenetre);

    expect(array_keys($sansTexte))->toBe(['sante', 'tache_planifiee', 'exception', 'echec_de_job', 'erreur_navigateur'])
        ->and(array_map(static fn (string $chemin): mixed => data_get($sansTexte, $chemin), ['tache_planifiee.id', 'exception.id', 'echec_de_job.uuid', 'erreur_navigateur.empreinte']))
        ->toBe([$echouee->id, $exception->id, $uuid, md5('e')])
        ->and(json_encode($sansTexte))->not->toContain('non_fiable')
        ->and(data_get($avecTexte, 'exception.non_fiable'))->toBe(['message' => 'boum', 'chemin' => '/'])
        ->and(data_get($avecTexte, 'echec_de_job.non_fiable'))->toBe(['message' => 'boum'])
        ->and(data_get($avecTexte, 'erreur_navigateur.non_fiable'))->toBe(['message' => 'm', 'page' => '/', 'pile' => ''])
        ->and(array_keys(enveloppeDiagnosticDetails([PorteeDeDiagnostic::Files], $fenetre)))->toBe(['echec_de_job'])
        ->and(enveloppeDiagnosticDetails([PorteeDeDiagnostic::TexteBrut], $fenetre))->toBe([]);
});

it('détaille la première tâche quand aucune n’a échoué, et rien quand il n’y a rien', function (): void {
    $premiere = TachePlanifiee::query()->create(['name' => 'a:ordinaire', 'type' => 'command', 'cron_expression' => '0 18 * * *', 'grace_time_in_minutes' => 5]);
    TachePlanifiee::query()->create(['name' => 'b:ordinaire', 'type' => 'command', 'cron_expression' => '0 18 * * *', 'grace_time_in_minutes' => 5]);
    $fenetre = FenetreDeDiagnostic::depuis(null, now());
    $portees = PorteeDeDiagnostic::SOURCES;

    $details = enveloppeDiagnosticDetails($portees, $fenetre);

    expect(data_get($details, 'tache_planifiee.id'))->toBe($premiere->id)
        ->and([$details['exception'], $details['echec_de_job'], $details['erreur_navigateur']])->toBe([null, null, null]);
});

/*
 * La forme des requêtes, pas leur nombre de lignes : une lecture sans borne
 * ne se verrait pas dans le résumé, qui compte au plus 5 000 lignes
 * (`LecturesBornees::bilan()`), mais lirait toute la table
 * (`.ai/rules/services.md`). Chaque agrégat, liste ou détail, lit une
 * ligne de plus que sa borne pour dire s'il a débordé : les quatre listes,
 * le détail d'une tâche et celui d'un groupe d'erreurs navigateur. Une
 * colonne de texte libre (message, page, pile, chemin, trace) n'est lue
 * entière que d'une ligne : 5 000 piles d'erreur navigateur, lues pour n'en
 * montrer qu'une, dépassaient la mémoire d'une requête.
 */
it('nomme ses colonnes et borne chaque lecture : ni select *, ni table lue sans limite, ni texte lu de plus d’une ligne', function (): void {
    $compte = User::factory()->create();
    ExceptionEnregistree::query()->create(['type' => RuntimeException::class, 'code' => '0', 'message' => 'boum', 'file' => 'f', 'line' => 1, 'trace' => [], 'method' => 'GET', 'path' => '/']);
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'RuntimeException: boum', 'failed_at' => now()]);
    ErreurNavigateur::query()->create(['user_id' => $compte->id, 'type' => 'error', 'message' => 'm', 'url' => '/', 'empreinte' => md5('e')]);
    $tache = TachePlanifiee::query()->create(['name' => 'b:echouee', 'type' => 'command', 'cron_expression' => '0 18 * * *', 'grace_time_in_minutes' => 5, 'last_failed_at' => now()]);
    DB::table('monitored_scheduled_task_log_items')->insert(['monitored_scheduled_task_id' => $tache->id, 'type' => 'failed', 'meta' => json_encode(['failure_message' => 'boum']), 'created_at' => now(), 'updated_at' => now()]);
    $fenetre = FenetreDeDiagnostic::jours(30, now());

    DB::flushQueryLog();
    DB::enableQueryLog();
    $resume = new ResumeDuDiagnostic()(PorteeDeDiagnostic::cases(), $fenetre);
    new ApercuDesDetails()(PorteeDeDiagnostic::cases(), $fenetre, $resume->enveloppe['donnees']);
    $lectures = array_map(static fn (array $requete): string => (string) $requete['query'], DB::getQueryLog());
    DB::disableQueryLog();

    $sansBorne = array_filter($lectures, static fn (string $requete): bool => preg_match('/ limit \d+$|`id` in \(\?(?:, \?){0,49}\)$/', $requete) !== 1);
    $texteEntier = array_values(array_filter($lectures, static fn (string $requete): bool => preg_match('/`(?:message|url|pile|path|trace)`/', $requete) === 1));

    expect($lectures)->toHaveCount(13)
        ->and(array_filter($lectures, static fn (string $requete): bool => str_contains($requete, '*')))->toBe([])
        ->and($sansBorne)->toBe([])
        ->and(array_filter($lectures, static fn (string $requete): bool => str_contains($requete, 'limit '.(LecturesBornees::LIGNES_AU_PLUS + 1))))->toHaveCount(6)
        ->and($texteEntier)->toHaveCount(2)
        ->and(array_filter($texteEntier, static fn (string $requete): bool => ! str_ends_with($requete, ' limit 1')))->toBe([]);
});
