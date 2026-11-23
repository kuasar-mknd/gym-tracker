<?php

declare(strict_types=1);

/*
 * La carte « Cette semaine » compare la semaine en cours à la précédente sur
 * la même durée écoulée (#2434) : du lundi 00:00 jusqu'à maintenant, face au
 * lundi précédent à 00:00 jusqu'au même jour et à la même heure. Comparée à la
 * semaine précédente entière, elle annonçait « −100 % » le lundi matin.
 *
 * L'horloge est arrêtée un mercredi à midi, dans la semaine du lundi 15 au
 * dimanche 21 juin 2026 ; la précédente va du 8 au 14. Chaque volume est posé,
 * jamais tiré.
 */

use App\DTOs\Stats\VolumeComparison;
use App\Models\User;
use App\Models\Workout;
use App\Services\Stats\ClesDeStats;
use App\Services\Stats\VolumeStatsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Une séance au volume posé, à l'instant posé.
 */
function seanceDeLaSemaine(User $compte, string $debut, float $volume): void
{
    Workout::factory()->create([
        'user_id' => $compte->id,
        'started_at' => Carbon::parse($debut),
        'ended_at' => Carbon::parse($debut)->addHour(),
        'workout_volume' => $volume,
    ]);
}

function comparaisonDeLaSemaine(User $compte): VolumeComparison
{
    return app(VolumeStatsService::class)->getWeeklyVolumeComparison($compte);
}

beforeEach(function (): void {
    Cache::flush();
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('compare le volume de la semaine à celui de la précédente jusqu’au même jour et à la même heure', function (): void {
    $compte = User::factory()->create();

    // Semaine précédente : le mardi 9 (avant le mercredi 12h00) et le vendredi 12 (après).
    seanceDeLaSemaine($compte, '2026-06-09 10:00:00', 100);
    seanceDeLaSemaine($compte, '2026-06-12 10:00:00', 900);
    // Semaine en cours : le mardi 16.
    seanceDeLaSemaine($compte, '2026-06-16 10:00:00', 150);

    $comparaison = comparaisonDeLaSemaine($compte);

    // 900 est hors de la durée écoulée : l'écart est +50 % contre 100, pas −85 % contre 1 000.
    expect($comparaison->current_volume)->toBe(150.0)
        ->and($comparaison->previous_volume)->toBe(100.0)
        ->and($comparaison->difference)->toBe(50.0)
        ->and($comparaison->percentage)->toBe(50.0)
        ->and($comparaison->sessions_count)->toBe(1);
});

it('tient la borne à la seconde près : la séance du même instant la semaine passée compte, la suivante non', function (): void {
    $compte = User::factory()->create();

    seanceDeLaSemaine($compte, '2026-06-10 12:00:00', 100);
    seanceDeLaSemaine($compte, '2026-06-10 12:00:01', 400);
    seanceDeLaSemaine($compte, '2026-06-16 10:00:00', 200);

    $comparaison = comparaisonDeLaSemaine($compte);

    expect($comparaison->previous_volume)->toBe(100.0)
        ->and($comparaison->percentage)->toBe(100.0);
});

it('ne dit aucun écart le lundi matin tant que la semaine passée n’avait encore rien à cette heure', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 08:00:00'));
    $compte = User::factory()->create();

    // La semaine passée a ses séances le mercredi : rien avant le lundi 8h00.
    seanceDeLaSemaine($compte, '2026-06-10 10:00:00', 500);
    seanceDeLaSemaine($compte, '2026-06-15 06:00:00', 200);

    $comparaison = comparaisonDeLaSemaine($compte);

    expect($comparaison->previous_volume)->toBe(0.0)
        ->and($comparaison->percentage)->toBeNull()
        ->and($comparaison->sessions_count)->toBe(1);
});

it('ne dit aucun écart, ni « −100 % », quand la semaine en cours n’a aucune séance', function (): void {
    $compte = User::factory()->create();

    seanceDeLaSemaine($compte, '2026-06-09 10:00:00', 500);

    $comparaison = comparaisonDeLaSemaine($compte);

    expect($comparaison->current_volume)->toBe(0.0)
        ->and($comparaison->previous_volume)->toBe(500.0)
        ->and($comparaison->sessions_count)->toBe(0)
        ->and($comparaison->percentage)->toBeNull();
});

it('compte les séances de la semaine, et seulement les siennes', function (): void {
    $compte = User::factory()->create();

    seanceDeLaSemaine($compte, '2026-06-14 22:00:00', 300);
    seanceDeLaSemaine($compte, '2026-06-15 07:00:00', 100);
    seanceDeLaSemaine($compte, '2026-06-17 09:00:00', 100);
    seanceDeLaSemaine(User::factory()->create(), '2026-06-16 10:00:00', 999);

    $comparaison = comparaisonDeLaSemaine($compte);

    expect($comparaison->sessions_count)->toBe(2)
        ->and($comparaison->current_volume)->toBe(200.0);
});

it('arrête la semaine au dimanche soir, comme les sept barres de la carte', function (): void {
    $compte = User::factory()->create();

    seanceDeLaSemaine($compte, '2026-06-16 10:00:00', 100);
    // Une séance posée après la semaine : ni dans le total, ni dans le compte.
    seanceDeLaSemaine($compte, '2026-06-22 09:00:00', 700);

    $comparaison = comparaisonDeLaSemaine($compte);

    expect($comparaison->current_volume)->toBe(100.0)
        ->and($comparaison->sessions_count)->toBe(1);
});

it('lit tout dans une seule requête, et garde le résultat sous la clef de la semaine', function (): void {
    $compte = User::factory()->create();
    seanceDeLaSemaine($compte, '2026-06-16 10:00:00', 100);

    DB::flushQueryLog();
    DB::enableQueryLog();
    comparaisonDeLaSemaine($compte);
    comparaisonDeLaSemaine($compte);
    $instructions = DB::getQueryLog();
    DB::disableQueryLog();

    // La version des statistiques vit dans le cache : seule la lecture des séances touche la base.
    expect($instructions)->toHaveCount(1)
        ->and(Cache::has(ClesDeStats::seances($compte, 'weekly_volume_to_date.2026-25')))->toBeTrue();
});

it('ne laisse pas la comparaison de la semaine entière d’avant servir à la place : la clef a changé', function (): void {
    $compte = User::factory()->create();
    seanceDeLaSemaine($compte, '2026-06-16 10:00:00', 100);

    // Une entrée d'avant la structure B, sans le compte des séances, sous l'ancienne clef.
    Cache::put(
        ClesDeStats::seances($compte, 'weekly_volume_comparison.2026-25'),
        ['current_volume' => 1.0, 'previous_volume' => 1.0, 'difference' => 0.0, 'percentage' => 0.0],
        600,
    );

    expect(comparaisonDeLaSemaine($compte)->sessions_count)->toBe(1);
});
