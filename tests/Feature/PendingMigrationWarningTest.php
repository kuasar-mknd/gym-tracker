<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * A database behind the code fails at the write, never at the read.
 *
 * The page renders, the request 500s on the insert, and the frontend rolls its
 * optimistic update back — so the app looks healthy while every save is
 * refused. That is what happened when activity_log was missing a column added
 * days earlier: adding an exercise, deleting one, logging a set, all of it
 * broke at once and nothing anywhere said why.
 *
 * No test suite can catch that by running: it migrates a fresh database every
 * time, so the condition cannot exist inside it. The guard has to be in the
 * app, and this is what checks the guard.
 */
it('counts the migrations the database has not run', function (): void {
    // The suite runs as `testing`; the guard is deliberately local-only, so the
    // environment has to be the one it is written for.
    app()['env'] = 'local';

    $user = User::factory()->create();

    actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('pending_migrations', 0));

    // Forgetting a migration is exactly what a stale database looks like.
    $forgotten = DB::table('migrations')->orderByDesc('id')->firstOrFail();
    DB::table('migrations')->where('id', $forgotten->id)->delete();

    actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('pending_migrations', 1));

    DB::table('migrations')->insert([
        'migration' => $forgotten->migration,
        'batch' => $forgotten->batch,
    ]);
});

/**
 * Production pays nothing for this: no query, no prop, no banner.
 */
it('does not look at the database outside local', function (): void {
    app()['env'] = 'production';

    $user = User::factory()->create();

    actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('pending_migrations', null));
});
