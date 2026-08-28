<?php

declare(strict_types=1);

use App\Actions\Habits\FetchHabitLogsIndexApiAction;
use App\Actions\Habits\FetchHabitsIndexAction;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function lecturesHabitudes(callable $geste): int
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

function semerJournaux(User $user, int $jours): void
{
    $habitudes = Habit::factory()->count(5)->create(['user_id' => $user->id, 'archived' => false]);
    $lignes = [];

    foreach ($habitudes as $habitude) {
        for ($jour = 0; $jour < $jours; $jour++) {
            $lignes[] = [
                'habit_id' => $habitude->id,
                'user_id' => $user->id,
                'date' => Carbon::now()->subDays($jour)->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
    }

    foreach (array_chunk($lignes, 500) as $lot) {
        HabitLog::insert($lot);
    }
}

/**
 * Le graphique de regularite ne regarde que trente jours, mais les lisait tous :
 * la jointure vers `habits` portait le filtre utilisateur, et `DATE()` sur une
 * colonne deja de type `date` interdisait l'index sans rien changer a la valeur.
 * Mesure : 492 lectures a 300 journaux, 1 692 a 1 500.
 */
it('lit autant d’index pour trente jours, quelle que soit la profondeur', function (): void {
    $court = User::factory()->create();
    semerJournaux($court, 60);

    $long = User::factory()->create();
    semerJournaux($long, 300);

    $action = new FetchHabitsIndexAction();

    lecturesHabitudes(fn (): array => $action->getStatsData($court));

    $petit = lecturesHabitudes(fn (): array => $action->getStatsData($court));
    $grand = lecturesHabitudes(fn (): array => $action->getStatsData($long));

    expect($grand)->toBe($petit);
});

it('sert la liste paginée sans joindre les habitudes', function (): void {
    $user = User::factory()->create();
    semerJournaux($user, 20);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $action = new FetchHabitLogsIndexApiAction();
    $page = $action->execute($user);
    $requetes = array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog());
    DB::disableQueryLog();

    expect($page->total())->toBe(100)
        ->and($page->items())->toHaveCount(15);

    foreach ($requetes as $sql) {
        expect($sql)->not->toContain('join `habits`');
    }
});

it('ne rend pas les journaux d’un autre utilisateur', function (): void {
    $user = User::factory()->create();
    $autre = User::factory()->create();
    semerJournaux($autre, 5);

    $action = new FetchHabitLogsIndexApiAction();

    expect($action->execute($user)->total())->toBe(0);
});

it('pose le propriétaire à l’écriture', function (): void {
    $habitude = Habit::factory()->create();

    $journal = $habitude->logs()->create(['date' => '2026-08-27']);

    expect($journal->refresh()->user_id)->toBe($habitude->user_id);
});

it('suit le propriétaire quand le journal change d’habitude', function (): void {
    $depart = Habit::factory()->create();
    $arrivee = Habit::factory()->create();
    $journal = $depart->logs()->create(['date' => '2026-08-27']);

    $journal->update(['habit_id' => $arrivee->id]);

    expect($journal->refresh()->user_id)->toBe($arrivee->user_id);
});
