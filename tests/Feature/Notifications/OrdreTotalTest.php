<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\PersonalRecordAchieved;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function vingtCinqNotifications(User $user): void
{
    // Ecrites d'un bloc : toutes portent la MEME seconde, ce qui est le cas
    // reel qu'on veut couvrir — un record en envoie trois d'un coup.
    $user->notifications()->createMany(
        collect(range(1, 25))->map(fn (int $rang): array => [
            'id' => (string) Str::uuid(),
            'type' => PersonalRecordAchieved::class,
            'data' => ['type' => 'personal_record', 'title' => "Record {$rang}"],
            'read_at' => null,
        ])->all()
    );
}

/**
 * `created_at` seul ne suffit pas a ordonner une liste paginee.
 *
 * Plusieurs notifications peuvent naitre dans la meme seconde. MySQL rend alors
 * ce qu'il veut, et rien ne garantit qu'il rende deux fois la meme chose : une
 * notification pouvait apparaitre sur les deux pages pendant qu'une autre
 * n'apparaissait sur aucune.
 *
 * C'est le defaut qu'un test Dusk attrapait par intermittence — donc un test
 * qui passait la plupart du temps sur un vrai defaut.
 */
it('ne répète aucune notification d’une page à l’autre', function (): void {
    $user = User::factory()->create();
    vingtCinqNotifications($user);

    $premiere = $user->notifications()->paginate(20, ['*'], 'page', 1);
    $seconde = $user->notifications()->paginate(20, ['*'], 'page', 2);

    /** @var list<string> $idsPremiere */
    $idsPremiere = $premiere->pluck('id')->values()->all();
    /** @var list<string> $idsSeconde */
    $idsSeconde = $seconde->pluck('id')->values()->all();

    expect($idsSeconde)->toHaveCount(5)
        ->and(array_intersect($idsPremiere, $idsSeconde))->toBe([])
        ->and(array_unique(array_merge($idsPremiere, $idsSeconde)))->toHaveCount(25);
});

/**
 * Le controle porte sur la FORME.
 *
 * Un test qui compte sur MySQL pour desordonner effectivement passe la plupart
 * du temps — verifie, trois fois de suite sur `main` — et ne tombe qu'au hasard
 * du plan. C'est exactement ce que faisait le test Dusk qui a leve ce defaut :
 * un test qui passait presque toujours sur un vrai defaut.
 */
it('ordonne les notifications sur deux colonnes', function (): void {
    $user = User::factory()->create();
    // Sans une ligne, le paginateur s'arrete au decompte et n'emet jamais la
    // lecture qu'on veut inspecter.
    vingtCinqNotifications($user);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $user->notifications()->paginate(20);
    $requetes = array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog());
    DB::disableQueryLog();

    $lectures = array_values(array_filter($requetes, fn (string $sql): bool => str_contains($sql, ' limit ')));

    expect($lectures)->not->toBeEmpty();

    foreach ($lectures as $sql) {
        expect($sql)->toContain('order by `created_at` desc, `id` desc');
    }
});

it('rend le même ordre à chaque lecture', function (): void {
    $user = User::factory()->create();
    vingtCinqNotifications($user);

    $premiere = $user->notifications()->pluck('id')->all();
    $seconde = $user->notifications()->pluck('id')->all();

    expect($seconde)->toBe($premiere);
});
