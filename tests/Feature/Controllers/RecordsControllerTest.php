<?php

declare(strict_types=1);

use App\Enums\PersonalRecordType;
use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('un invité est renvoyé à la connexion', function (): void {
    get(route('records.index'))->assertRedirect(route('login'));
});

test('un compte sans record voit une page vide, sans erreur', function (): void {
    actingAs(User::factory()->create())
        ->get(route('records.index'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('Records/Index')
                ->has('records', 0)
                ->where('tronque', false)
        );
});

test('la page rend les records du compte, exercice par exercice', function (): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Squat']);

    foreach ([
        [PersonalRecordType::MaxWeight, 140, 3],
        [PersonalRecordType::Max1RM, 154, 140],
        [PersonalRecordType::MaxVolumeSet, 420, null],
    ] as [$type, $valeur, $secondaire]) {
        PersonalRecord::factory()->createQuietly([
            'user_id' => $user->id,
            'exercise_id' => $exercice->id,
            'workout_id' => null,
            'type' => $type,
            'value' => $valeur,
            'secondary_value' => $secondaire,
            'achieved_at' => '2026-11-10 09:30:00',
        ]);
    }

    actingAs($user)
        ->get(route('records.index'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('Records/Index')
                ->has('records', 1)
                ->where('records.0.nom', 'Squat')
                ->where('records.0.exercice_id', $exercice->id)
                ->where('records.0.date', '2026-11-10')
                ->where('records.0.poids_max', ['valeur' => 140, 'repetitions' => 3, 'date' => '2026-11-10'])
                ->where('records.0.un_rm', ['valeur' => 154, 'date' => '2026-11-10'])
                ->where('records.0.volume_serie', ['valeur' => 420, 'date' => '2026-11-10'])
                ->where('tronque', false)
        );
});

test('la page ne montre rien du compte d’un autre', function (): void {
    $autre = User::factory()->create();
    PersonalRecord::factory()->createQuietly([
        'user_id' => $autre->id,
        'exercise_id' => Exercise::factory()->create(['user_id' => $autre->id])->id,
        'workout_id' => null,
        'value' => 999,
    ]);

    actingAs(User::factory()->create())
        ->get(route('records.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->has('records', 0));
});

test('la page n’écrit rien en base', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->createQuietly([
        'user_id' => $user->id,
        'exercise_id' => Exercise::factory()->create(['user_id' => $user->id])->id,
        'workout_id' => null,
    ]);
    Cache::flush();

    $ecritures = [];
    DB::listen(function (QueryExecuted $query) use (&$ecritures): void {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $ecritures[] = $query->sql;
        }
    });

    actingAs($user)->get(route('records.index'))->assertOk();
    actingAs($user)->get(route('records.index'))->assertOk();

    expect($ecritures)->toBe([]);
});
