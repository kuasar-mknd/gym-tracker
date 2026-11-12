<?php

declare(strict_types=1);

use App\Actions\Habits\FetchHabitsIndexAction;
use App\Actions\Measurements\FetchBodyPartMeasurementsIndexAction;
use App\Actions\Supplements\FetchSupplementsIndexAction;
use App\Models\BodyPartMeasurement;
use App\Models\Habit;
use App\Models\Supplement;
use App\Models\User;
use App\Models\Workout;
use App\Services\Stats\VolumeStatsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * Un tri sur une colonne qui se répète, ou sans ordre du tout, laisse le plan
 * choisir : l'ajout d'un index change alors les lignes rendues. Le test porte sur
 * le SQL exécuté, parce que l'ordre des lignes peut sortir juste sans le
 * départage.
 */

/**
 * Les requêtes SQL émises par le rappel, en minuscules.
 *
 * @return list<string>
 */
function sqlEmisPar(Closure $rappel): array
{
    Cache::flush();
    $requetes = [];
    DB::listen(function ($requete) use (&$requetes): void {
        $requetes[] = strtolower($requete->sql);
    });

    $rappel();

    return $requetes;
}

/**
 * @param  list<string>  $requetes
 * @return list<string>
 */
function requetesSur(array $requetes, string $table): array
{
    return array_values(array_filter($requetes, fn (string $sql): bool => str_contains($sql, "from `{$table}`")));
}

test('la dernière mesure de chaque partie est départagée par la clef primaire', function (): void {
    $user = User::factory()->create();
    BodyPartMeasurement::factory()->count(2)->create(['user_id' => $user->id, 'part' => 'Waist']);

    $requetes = requetesSur(sqlEmisPar(fn (): array => new FetchBodyPartMeasurementsIndexAction()->execute($user)), 'body_part_measurements');

    expect($requetes)->not->toBeEmpty();
    foreach ($requetes as $sql) {
        expect($sql)->toContain('order by `measured_at` desc, `id` desc');
    }
});

test('la tendance et l’historique de volume sont départagés par la clef primaire', function (): void {
    $user = User::factory()->create();
    Workout::factory()->create(['user_id' => $user->id, 'started_at' => now(), 'ended_at' => now()]);
    $service = resolve(VolumeStatsService::class);

    $tendance = requetesSur(sqlEmisPar(fn () => $service->getVolumeTrend($user)), 'workouts');
    $historique = requetesSur(sqlEmisPar(fn () => $service->getVolumeHistory($user)), 'workouts');

    expect($tendance)->not->toBeEmpty()
        ->and($tendance[0])->toContain('order by `started_at` asc, `id` asc')
        ->and($historique)->not->toBeEmpty()
        ->and($historique[0])->toContain('order by `started_at` desc, `id` desc');
});

test('les habitudes et les compléments ont un ordre explicite', function (): void {
    $user = User::factory()->create();
    Habit::factory()->create(['user_id' => $user->id]);
    Supplement::factory()->create(['user_id' => $user->id]);

    $habitudes = requetesSur(sqlEmisPar(fn () => new FetchHabitsIndexAction()->getImmediateData($user)['habits']()), 'habits');
    $complements = requetesSur(sqlEmisPar(fn (): array => new FetchSupplementsIndexAction()->execute($user)), 'supplements');

    expect($habitudes)->not->toBeEmpty()
        ->and($habitudes[0])->toContain('order by `id` asc')
        ->and($complements)->not->toBeEmpty()
        ->and($complements[0])->toContain('order by `id` asc');
});
