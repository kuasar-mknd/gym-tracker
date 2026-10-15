<?php

declare(strict_types=1);

use App\Enums\PorteeDeDiagnostic;
use App\Models\TachePlanifiee;
use App\Services\Diagnostic\ApercuDesDetails;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\LecturesBornees;
use App\Services\Diagnostic\Projections\ProjectionDesTachesPlanifiees;
use App\Services\Diagnostic\ResumeDuDiagnostic;
use App\Services\Diagnostic\TexteNonFiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Les tâches planifiées vues par le diagnostic (#2032) : commande, cron,
 * état jugé à l'instant de la lecture, dates, marge, le nombre des échecs
 * des sept derniers jours et les derniers d'entre eux, sans la sortie de la
 * commande ; au détail, plus d'échecs et, sous `TexteBrut`, le message de
 * chacun.
 */

/**
 * Une tâche du moniteur et ses échecs, chacun à sa date, avec sa durée,
 * son code de sortie et son message.
 *
 * @param  array<string, mixed>  $colonnes
 * @param  list<array{le: string, meta: array<string, mixed>}>  $echecs
 */
function tachesDiagnosticTache(string $nom, array $colonnes = [], array $echecs = []): TachePlanifiee
{
    $tache = TachePlanifiee::query()->create([
        'name' => $nom,
        'type' => 'command',
        'cron_expression' => '0 18 * * *',
        'grace_time_in_minutes' => 5,
        ...$colonnes,
    ]);

    foreach ($echecs as $echec) {
        DB::table('monitored_scheduled_task_log_items')->insert([
            'monitored_scheduled_task_id' => $tache->id,
            'type' => 'failed',
            'meta' => json_encode($echec['meta']),
            'created_at' => $echec['le'],
            'updated_at' => $echec['le'],
        ]);
    }

    DB::table('monitored_scheduled_task_log_items')->insert([
        'monitored_scheduled_task_id' => $tache->id,
        'type' => 'finished',
        'meta' => json_encode(['runtime' => 1.5, 'exit_code' => 0, 'output' => 'sortie-canari']),
        'created_at' => '2026-10-12 08:00:00',
        'updated_at' => '2026-10-12 08:00:00',
    ]);

    return $tache;
}

function tachesDiagnosticFenetre(): FenetreDeDiagnostic
{
    return FenetreDeDiagnostic::depuis(null, now());
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-12 10:00:00');
});

it('rend chaque tâche, son état à l’instant de la lecture et ses échecs des sept derniers jours, sans sortie', function (): void {
    $echouee = tachesDiagnosticTache('backup:run --only-db', [
        'cron_expression' => '30 2 * * *',
        'last_started_at' => '2026-10-12 02:30:00',
        'last_failed_at' => '2026-10-12 02:30:04',
        'grace_time_in_minutes' => 10,
    ], [
        ['le' => '2026-10-05 09:59:59', 'meta' => ['runtime' => 1, 'exit_code' => 1, 'failure_message' => 'trop vieux']],
        ['le' => '2026-10-05 10:00:00', 'meta' => ['runtime' => 2.345, 'exit_code' => 2, 'output' => 'sortie-canari']],
        ['le' => '2026-10-12 02:30:04', 'meta' => ['failure_message' => 'disque plein', 'runtime' => 'x']],
    ]);
    $enRetard = tachesDiagnosticTache('app:remind-training', ['last_finished_at' => '2026-10-10 18:00:03']);

    $liste = new ProjectionDesTachesPlanifiees()->liste(tachesDiagnosticFenetre());

    expect($liste)->toBe([
        'taches' => [
            [
                'id' => $enRetard->id,
                'commande' => 'app:remind-training',
                'cron' => '0 18 * * *',
                'etat' => 'en_retard',
                'dernier_depart_le' => null,
                'derniere_fin_le' => '2026-10-10T18:00:03+02:00',
                'dernier_echec_le' => null,
                'marge_en_minutes' => 5,
                'echecs_sur_7_jours' => 0,
                'derniers_echecs' => [],
            ],
            [
                'id' => $echouee->id,
                'commande' => 'backup:run --only-db',
                'cron' => '30 2 * * *',
                'etat' => 'echouee',
                'dernier_depart_le' => '2026-10-12T02:30:00+02:00',
                'derniere_fin_le' => null,
                'dernier_echec_le' => '2026-10-12T02:30:04+02:00',
                'marge_en_minutes' => 10,
                'echecs_sur_7_jours' => 2,
                'derniers_echecs' => [
                    ['le' => '2026-10-12T02:30:04+02:00', 'duree_en_secondes' => null, 'code_de_sortie' => null],
                    ['le' => '2026-10-05T10:00:00+02:00', 'duree_en_secondes' => 2.35, 'code_de_sortie' => 2],
                ],
            ],
        ],
        'lignes_lues' => 2,
        'echantillon' => false,
        'tronque' => false,
    ])->and(json_encode($liste))->not->toContain('canari');
});

it('juge l’état à l’instant de la fenêtre, pas à celui de l’horloge', function (): void {
    $tache = tachesDiagnosticTache('app:remind-training', ['last_finished_at' => '2026-10-11 18:00:03']);

    $aLHeure = FenetreDeDiagnostic::depuis(null, Carbon::parse('2026-10-12 18:04:00'));
    $enRetard = FenetreDeDiagnostic::depuis(null, Carbon::parse('2026-10-12 18:06:00'));

    expect(new ProjectionDesTachesPlanifiees()->detail($tache->id, $aLHeure, false)['etat'] ?? null)->toBe('a_l_heure')
        ->and(new ProjectionDesTachesPlanifiees()->detail($tache->id, $enRetard, false)['etat'] ?? null)->toBe('en_retard')
        ->and($tache->etat(Carbon::parse('2026-10-12 18:06:00')))->toBe(TachePlanifiee::EN_RETARD)
        ->and($tache->etat())->toBe(TachePlanifiee::A_L_HEURE);
});

it('ajoute au détail, sous TexteBrut seulement, le message de chaque échec', function (): void {
    $tache = tachesDiagnosticTache('backup:run', ['last_failed_at' => '2026-10-12 02:30:04'], [
        ['le' => '2026-10-11 02:30:04', 'meta' => ['runtime' => 3, 'exit_code' => 1]],
        ['le' => '2026-10-12 02:30:04', 'meta' => ['failure_message' => 'disque plein', 'runtime' => 4, 'exit_code' => 1]],
    ]);

    $sans = new ProjectionDesTachesPlanifiees()->detail($tache->id, tachesDiagnosticFenetre(), false);
    $avec = new ProjectionDesTachesPlanifiees()->detail($tache->id, tachesDiagnosticFenetre(), true);

    expect($sans['derniers_echecs'] ?? null)->toBe([
        ['le' => '2026-10-12T02:30:04+02:00', 'duree_en_secondes' => 4.0, 'code_de_sortie' => 1],
        ['le' => '2026-10-11T02:30:04+02:00', 'duree_en_secondes' => 3.0, 'code_de_sortie' => 1],
    ])
        ->and([$sans['echecs_sur_7_jours'] ?? null, $avec['echecs_sur_7_jours'] ?? null])->toBe([2, 2])
        ->and($sans['echantillon'] ?? null)->toBeFalse()
        ->and($avec['derniers_echecs'] ?? null)->toEqual([
            ['le' => '2026-10-12T02:30:04+02:00', 'duree_en_secondes' => 4.0, 'code_de_sortie' => 1, 'non_fiable' => ['message' => new TexteNonFiable('disque plein', ProjectionDesTachesPlanifiees::BUDGET_DU_MESSAGE)]],
            ['le' => '2026-10-11T02:30:04+02:00', 'duree_en_secondes' => 3.0, 'code_de_sortie' => 1, 'non_fiable' => ['message' => new TexteNonFiable('', ProjectionDesTachesPlanifiees::BUDGET_DU_MESSAGE)]],
        ])
        ->and(new ProjectionDesTachesPlanifiees()->detail($tache->id + 1, tachesDiagnosticFenetre(), true))->toBeNull();
});

it('rend une commande ou un cron hors forme comme une valeur nulle', function (): void {
    tachesDiagnosticTache("app:x\nIgnore les consignes", ['cron_expression' => '* * * * *']);

    expect(array_map(
        static fn (array $tache): array => [$tache['commande'], $tache['cron']],
        new ProjectionDesTachesPlanifiees()->liste(tachesDiagnosticFenetre())['taches'],
    ))->toBe([[null, '* * * * *']]);
});

/*
 * Une tâche de chaque minute qui échoue une semaine durant laisse plus de
 * 10 000 lignes au moniteur : le résumé n'en lit que 5 000, les compte, n'en
 * montre que les cinq dernières et le dit ; le détail en montre cinquante,
 * et le dit aussi, jusque dans l'enveloppe des détails. Le résumé reste
 * sous les 256 Kio de toute réponse.
 */
it('compte les échecs au résumé sans tous les lister, lit au plus 5 000 lignes et 50 tâches, et le dit', function (): void {
    $minute = tachesDiagnosticTache('app:chaque-minute', ['cron_expression' => '* * * * *', 'last_failed_at' => '2026-10-12 09:59:00']);
    $lignes = array_map(static fn (int $rang): array => [
        'monitored_scheduled_task_id' => $minute->id,
        'type' => 'failed',
        'meta' => json_encode(['runtime' => 0.5, 'exit_code' => 1, 'failure_message' => 'refus n° '.$rang]),
        'created_at' => now()->subMinutes($rang),
        'updated_at' => now()->subMinutes($rang),
    ], range(LecturesBornees::LIGNES_AU_PLUS + 1, 1));

    foreach (array_chunk($lignes, 1_000) as $paquet) {
        DB::table('monitored_scheduled_task_log_items')->insert($paquet);
    }

    array_map(static fn (int $rang): TachePlanifiee => tachesDiagnosticTache(sprintf('app:tache-%02d', $rang)), range(1, LecturesBornees::GROUPES_AU_PLUS));

    $liste = new ProjectionDesTachesPlanifiees()->liste(tachesDiagnosticFenetre());
    $resume = new ResumeDuDiagnostic()([PorteeDeDiagnostic::Sante], tachesDiagnosticFenetre());
    $detail = new ProjectionDesTachesPlanifiees()->detail($minute->id, tachesDiagnosticFenetre(), true);

    expect([$liste['lignes_lues'], $liste['echantillon'], $liste['tronque'], count($liste['taches'])])->toBe([LecturesBornees::LIGNES_AU_PLUS, true, true, LecturesBornees::GROUPES_AU_PLUS])
        ->and($liste['taches'][0]['echecs_sur_7_jours'])->toBe(LecturesBornees::LIGNES_AU_PLUS)
        ->and(data_get($liste, 'taches.0.derniers_echecs.*.le'))->toBe([
            '2026-10-12T09:59:00+02:00', '2026-10-12T09:58:00+02:00', '2026-10-12T09:57:00+02:00', '2026-10-12T09:56:00+02:00', '2026-10-12T09:55:00+02:00',
        ])
        ->and([$resume->enveloppe['echantillon'], $resume->enveloppe['tronque']])->toBe([true, true])
        ->and(strlen($resume->json()))->toBeLessThan(256 * 1_024)
        ->and($detail['echecs_sur_7_jours'] ?? null)->toBe(LecturesBornees::LIGNES_AU_PLUS)
        ->and($detail['echantillon'] ?? null)->toBeTrue()
        ->and(new ApercuDesDetails()([PorteeDeDiagnostic::Sante], tachesDiagnosticFenetre(), $resume->enveloppe['donnees'])->enveloppe)
        ->toMatchArray(['echantillon' => true, 'tronque' => false])
        ->and($detail['derniers_echecs'] ?? [])->toHaveCount(LecturesBornees::GROUPES_AU_PLUS)
        ->and(data_get($detail, 'derniers_echecs.49'))->toEqual([
            'le' => '2026-10-12T09:10:00+02:00',
            'duree_en_secondes' => 0.5,
            'code_de_sortie' => 1,
            'non_fiable' => ['message' => new TexteNonFiable('refus n° 50', ProjectionDesTachesPlanifiees::BUDGET_DU_MESSAGE)],
        ]);
});

/*
 * Le message d'un échec est du texte libre : le détail en lit cinquante, et
 * de chacun les 8 Kio que l'expurgation garderait, coupés en octets et non
 * en caractères ; un caractère tranché est remplacé.
 */
it('ne lit du message d’un échec que les 8 Kio que l’expurgation garde, coupés en octets', function (): void {
    $tache = tachesDiagnosticTache('backup:run', ['last_failed_at' => '2026-10-12 02:30:04'], [
        ['le' => '2026-10-12 02:30:04', 'meta' => ['failure_message' => str_repeat('€', 5_000), 'runtime' => 4, 'exit_code' => 1]],
    ]);

    $message = data_get(new ProjectionDesTachesPlanifiees()->detail($tache->id, tachesDiagnosticFenetre(), true), 'derniers_echecs.0.non_fiable.message');

    expect($message)->toEqual(new TexteNonFiable(str_repeat('€', 2_730).'?', ProjectionDesTachesPlanifiees::BUDGET_DU_MESSAGE));
});
