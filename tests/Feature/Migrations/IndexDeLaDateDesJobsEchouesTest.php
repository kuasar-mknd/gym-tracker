<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * `failed_jobs.failed_at` est indexée (#2032) : `queue:prune-failed` la purge
 * par sa date, et le diagnostic la lit par sa date. La migration, rejouée sur
 * une base qui a déjà l'index, ne lance aucun ordre : pas de DDL ici, qui
 * validerait la transaction du test (`.ai/rules/tests.md`).
 */
it('indexe failed_at', function (): void {
    expect(Schema::hasIndex('failed_jobs', 'failed_jobs_failed_at_index'))->toBeTrue()
        ->and(Schema::hasIndex('failed_jobs', ['failed_at']))->toBeTrue();
});

it('ne lance aucun ordre quand l’index est déjà là', function (): void {
    $migration = require database_path('migrations/2026_11_12_200000_indexer_la_date_des_jobs_echoues.php');
    assert($migration instanceof Migration && method_exists($migration, 'up'));
    DB::flushQueryLog();
    DB::enableQueryLog();

    $migration->up();

    $alter = array_filter(DB::getQueryLog(), static fn (array $requete): bool => str_starts_with(mb_strtolower((string) $requete['query']), 'alter table'));
    DB::disableQueryLog();

    expect($alter)->toBe([]);
});

it('planifie la purge des jobs échoués à trente jours', function (): void {
    $purge = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(static fn ($evenement): bool => str_contains((string) $evenement->command, 'queue:prune-failed'));

    expect($purge)->not->toBeNull()
        ->and((string) $purge?->command)->toContain('--hours=720');
});
