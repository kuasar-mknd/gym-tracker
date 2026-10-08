<?php

declare(strict_types=1);

/*
 * Les parcours de jours d'App\Actions ne comptent plus leurs tours (#2017) :
 * ils parcourent un `range()` ou les jours d'une période. Ces tests tiennent ce
 * que la réécriture ne devait pas changer, aux deux endroits où une période
 * mal bornée le changeait.
 *
 * Minuit qui manque. Le fuseau se règle par APP_TIMEZONE, et à Santiago le
 * passage à l'heure d'été saute de 00:00 à 01:00 (le 6 septembre 2026). Une
 * période partie du début de la journée y passe chaque jour suivant à 01:00 :
 * entre 00:00 et 01:00, aujourd'hui dépassait maintenant et sortait de la
 * fenêtre. L'hydratation rendait six jours au lieu de sept, les compléments et
 * les statistiques d'habitudes vingt-neuf au lieu de trente, et ce qu'on avait
 * noté juste après minuit n'apparaissait nulle part.
 *
 * Minuit qui passe pendant le calcul. Deux lectures de l'horloge pour les deux
 * bornes d'une même fenêtre : si minuit tombe entre elles, la fenêtre s'élargit
 * d'un jour, ou d'une semaine pour la grille des habitudes. L'horloge de ces
 * tests passe minuit après la première lecture, la deuxième, la troisième ou la
 * quatrième, puisque l'endroit exact dépend de l'ordre des appels. Le total
 * d'eau du jour, qui ne parcourt aucun jour, tirait lui aussi ses deux bornes
 * de deux lectures : il comptait alors l'eau de la veille.
 */

use App\Actions\Habits\FetchHabitsIndexAction;
use App\Actions\Supplements\FetchSupplementsIndexAction;
use App\Actions\Tools\FetchWaterHistoryAction;
use App\Actions\Tools\FetchWaterTrackerAction;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use App\Models\WaterLog;
use Illuminate\Support\Carbon;
use Tests\Support\HorlogeQuiPasseMinuit;

afterEach(function (): void {
    date_default_timezone_set(config()->string('app.timezone'));
});

/**
 * Le fuseau de Santiago, comme le poserait APP_TIMEZONE au démarrage, le
 * lendemain du passage à l'heure d'été, une demi-heure après minuit : le
 * 6 septembre 2026, 00:00 n'a pas existé, et le 7, il n'est pas encore une
 * heure.
 */
function santiagoLeLendemainDuMinuitManquant(): void
{
    date_default_timezone_set('America/Santiago');
    Carbon::setTestNow(Carbon::parse('2026-09-07 00:30:00'));
}

/**
 * Les trente jours qui finissent le jour donné, du plus ancien au plus récent,
 * comme les étiquette l'historique des compléments.
 *
 * @return list<string>
 */
function trenteJoursDeComplementsFinissantLe(string $jour): array
{
    return array_map(
        static fn (int $joursAvant): string => Carbon::parse($jour)->subDays($joursAvant)->format('d/m'),
        range(29, 0),
    );
}

it('rend sept jours d hydratation, aujourd hui compris, là où minuit a manqué', function (): void {
    santiagoLeLendemainDuMinuitManquant();
    $user = User::factory()->create();
    WaterLog::factory()->create(['user_id' => $user->id, 'consumed_at' => Carbon::parse('2026-09-07 00:15:00'), 'amount' => 250]);

    $historique = app(FetchWaterHistoryAction::class)->execute($user);

    expect(array_column($historique, 'date'))->toBe(['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05', '2026-09-06', '2026-09-07'])
        ->and($historique[6]['total'])->toBe(250.0);
});

it('rend trente jours de compléments, aujourd hui compris, là où minuit a manqué', function (): void {
    santiagoLeLendemainDuMinuitManquant();
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id]);
    SupplementLog::create(['user_id' => $user->id, 'supplement_id' => $supplement->id, 'quantity' => 2, 'consumed_at' => Carbon::parse('2026-09-07 00:10:00')]);

    $historique = app(FetchSupplementsIndexAction::class)->execute($user)['usageHistory'];

    expect($historique)->toHaveCount(30)
        ->and($historique[0]['date'])->toBe('09/08')
        ->and($historique[29])->toBe(['date' => '07/09', 'count' => 2.0]);
});

it('rend trente jours de statistiques d habitudes, aujourd hui compris, là où minuit a manqué', function (): void {
    santiagoLeLendemainDuMinuitManquant();
    $user = User::factory()->create();
    $habitude = Habit::factory()->create(['user_id' => $user->id]);
    HabitLog::create(['habit_id' => $habitude->id, 'date' => '2026-09-07']);

    $stats = app(FetchHabitsIndexAction::class)->getStatsData($user);

    expect($stats['consistencyData'])->toHaveCount(30)
        ->and($stats['consistencyData'][0]['date'])->toBe('2026-08-09')
        ->and($stats['consistencyData'][29])->toBe(['date' => '2026-09-07', 'count' => 1])
        ->and($stats['history'])->toHaveCount(30);
});

it('rend trente jours de compléments d affilée quand minuit passe pendant le calcul', function (int $lectures): void {
    $user = User::factory()->create();
    HorlogeQuiPasseMinuit::apres($lectures, '2026-10-08 23:59:59.999999', '2026-10-09 00:00:00.000001');

    $historique = app(FetchSupplementsIndexAction::class)->execute($user)['usageHistory'];

    // Les trente jours d'avant minuit ou ceux d'après, selon la lecture qui
    // fixe la fenêtre, mais jamais trente et un.
    expect(array_column($historique, 'date'))->toBeIn([
        trenteJoursDeComplementsFinissantLe('2026-10-08 12:00:00'),
        trenteJoursDeComplementsFinissantLe('2026-10-09 12:00:00'),
    ]);
})->with([1, 2, 3, 4]);

it('rend une grille de sept jours, du lundi au dimanche, quand lundi commence pendant le calcul', function (int $lectures): void {
    $user = User::factory()->create();
    HorlogeQuiPasseMinuit::apres($lectures, '2026-10-11 23:59:59.999999', '2026-10-12 00:00:00.000001');

    $semaine = app(FetchHabitsIndexAction::class)->getImmediateData($user)['weekDates'];

    expect(array_column($semaine, 'day'))->toBe(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']);
})->with([1, 2, 3]);

it('rend l eau d un seul jour quand minuit passe pendant le calcul', function (int $lectures): void {
    $user = User::factory()->create();
    WaterLog::factory()->create(['user_id' => $user->id, 'consumed_at' => Carbon::parse('2026-10-08 12:00:00'), 'amount' => 250]);
    WaterLog::factory()->create(['user_id' => $user->id, 'consumed_at' => Carbon::parse('2026-10-09 00:00:00'), 'amount' => 500]);
    HorlogeQuiPasseMinuit::apres($lectures, '2026-10-08 23:59:59.999999', '2026-10-09 00:00:00.000001');

    $suivi = app(FetchWaterTrackerAction::class)->execute($user);

    // L'eau du 8 octobre ou celle du 9, selon la lecture qui fixe le jour,
    // jamais les deux réunies.
    expect($suivi['todayTotal'])->toBeIn([250, 500]);
})->with([1, 2, 3]);
