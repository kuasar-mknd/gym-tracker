<?php

declare(strict_types=1);

use App\Actions\Fasting\FetchFastingIndexAction;
use App\Models\Fast;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function lecturesTri(callable $geste): int
{
    $releve = function (): int {
        $total = 0;
        /** @var list<object{Value: string}> $compteurs */
        $compteurs = DB::select("show session status like 'Handler_read%'");

        foreach ($compteurs as $compteur) {
            $total += (int) $compteur->Value;
        }

        return $total;
    };

    $avant = $releve();
    $geste();

    return $releve() - $avant;
}

function semerJeunesEtAvis(User $user, int $lignes): void
{
    $jeunes = $avis = [];

    for ($i = 0; $i < $lignes; $i++) {
        $quand = Carbon::now()->subDays($i);
        $jeunes[] = ['user_id' => $user->id, 'status' => 'completed', 'type' => '16:8', 'start_time' => $quand, 'end_time' => $quand->copy()->addHours(16), 'target_duration_minutes' => 960, 'created_at' => $quand, 'updated_at' => now()];
        $avis[] = ['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\X', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => '{}', 'read_at' => $quand, 'created_at' => $quand, 'updated_at' => now()];
    }

    foreach ([['fasts', $jeunes], ['notifications', $avis]] as [$table, $donnees]) {
        foreach (array_chunk($donnees, 500) as $lot) {
            DB::table($table)->insert($lot);
        }
    }
}

/**
 * Deux tris qu'aucun index ne couvrait. La pagination bornait ce qui remonte,
 * pas le tri : MySQL classait tout l'historique avant d'en prendre une page.
 *
 * Le `COUNT(*)` de la pagination reste proportionnel — c'est ce que coute une
 * pagination par decalage — d'ou la comparaison du SURCOUT au-dela de ce
 * decompte, et non des lectures brutes.
 */
it('ne trie plus tout l’historique pour une page', function (): void {
    $court = User::factory()->create();
    semerJeunesEtAvis($court, 100);

    $long = User::factory()->create();
    semerJeunesEtAvis($long, 500);

    $jeunes = new FetchFastingIndexAction();

    $surcout = function (User $u) use ($jeunes): array {
        $lignes = DB::table('fasts')->where('user_id', $u->id)->count();

        return [
            'jeûnes' => lecturesTri(fn (): array => $jeunes->execute($u)) - $lignes,
            'notifications' => lecturesTri(fn () => $u->notifications()->paginate(20)) - $lignes,
        ];
    };

    $surcout($court);

    expect($surcout($long))->toBe($surcout($court));
});

it('range les jeûnes par heure de début, comme l’API', function (): void {
    $user = User::factory()->create();

    // Saisi apres coup, mais commence avant : l'ordre par `created_at` l'aurait
    // mis en tete, l'ordre par `start_time` le met en second.
    DB::table('fasts')->insert([
        ['user_id' => $user->id, 'status' => 'completed', 'type' => '16:8', 'start_time' => '2026-08-20 08:00:00', 'end_time' => '2026-08-21 00:00:00', 'target_duration_minutes' => 960, 'created_at' => '2026-08-20 09:00:00', 'updated_at' => now()],
        ['user_id' => $user->id, 'status' => 'completed', 'type' => '16:8', 'start_time' => '2026-08-10 08:00:00', 'end_time' => '2026-08-11 00:00:00', 'target_duration_minutes' => 960, 'created_at' => '2026-08-25 09:00:00', 'updated_at' => now()],
    ]);

    $action = new FetchFastingIndexAction();

    /** @var \Illuminate\Pagination\LengthAwarePaginator<int, Fast> $histoire */
    $histoire = $action->execute($user)['history'];
    $debuts = $histoire->getCollection()
        ->map(fn (Fast $jeune): string => (string) $jeune->start_time)
        ->values()
        ->all();

    expect($debuts[0])->toContain('2026-08-20')
        ->and($debuts[1])->toContain('2026-08-10');
});
