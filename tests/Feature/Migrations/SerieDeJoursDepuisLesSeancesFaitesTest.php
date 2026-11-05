<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Notifications\AchievementUnlocked;
use App\Notifications\PersonalRecordAchieved;
use App\Services\NotificationService;
use Database\Seeders\AchievementSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * La série de jours et les succès de type `streak` qu'un compte tient d'avant
 * #2171 comptaient toutes ses séances, vides ou en cours comprises.
 * `2026_11_05_060512_recalculer_la_serie_de_jours_depuis_les_seances_faites.php`
 * refait, une fois, la série stockée depuis les seules séances faites, par
 * l'écrivain (`StreakService::recalculerDepuisLesFaits()`), et retire les
 * succès de série que ces séances ne soutiennent pas, avec leurs annonces,
 * sans rien annoncer. La migration n'écrit aucun schéma : elle se joue dans la
 * transaction du test.
 */

beforeEach(function (): void {
    $this->seed(AchievementSeeder::class);
});

/**
 * Joue `up()` de la migration, comme `migrate`.
 */
function serieMigreeMigrer(): void
{
    $migration = require database_path('migrations/2026_11_05_060512_recalculer_la_serie_de_jours_depuis_les_seances_faites.php');
    assert($migration instanceof Migration && method_exists($migration, 'up'));

    $migration->up();
}

/**
 * Des séances du compte, écrites sans écouteurs : rien ne recalcule la série
 * avant la migration. Une séance faite est terminée une heure après son début,
 * avec une série cochée ; une séance vide est terminée sans rien ; une séance
 * en cours porte une série cochée, sans fin.
 *
 * @param  list<string>  $faites  Les débuts des séances faites.
 * @param  list<string>  $vides  Les débuts des séances terminées sans série.
 * @param  list<string>  $enCours  Les débuts des séances encore ouvertes.
 */
function serieMigreeSeances(User $compte, array $faites = [], array $vides = [], array $enCours = []): void
{
    Workout::withoutEvents(function () use ($compte, $faites, $vides, $enCours): void {
        foreach ($faites as $debut) {
            Workout::factory()->faite()->create(['user_id' => $compte->id, 'started_at' => Carbon::parse($debut)]);
        }

        foreach ($vides as $debut) {
            Workout::factory()->create([
                'user_id' => $compte->id,
                'started_at' => Carbon::parse($debut),
                'ended_at' => Carbon::parse($debut)->addHour(),
            ]);
        }

        foreach ($enCours as $debut) {
            $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => Carbon::parse($debut), 'ended_at' => null]);
            Set::factory()->create([
                'workout_line_id' => WorkoutLine::factory()->create(['workout_id' => $seance->id])->id,
            ]);
        }
    });
}

/**
 * Pose sur le compte la série qu'aurait écrite l'ancien écrivain.
 */
function serieMigreePoser(User $compte, ?string $derniere, int $enCours, int $plusLongue): void
{
    DB::table('users')->where('id', $compte->id)->update([
        'last_workout_at' => $derniere,
        'current_streak' => $enCours,
        'longest_streak' => $plusLongue,
    ]);
}

/**
 * La série stockée : dernière séance faite, série en cours, plus longue.
 *
 * @return array{0: string|null, 1: int, 2: int}
 */
function serieMigreeStockee(User $compte): array
{
    $enBase = User::query()->findOrFail($compte->id);

    return [$enBase->last_workout_at?->toDateTimeString(), $enBase->current_streak, $enBase->longest_streak];
}

/**
 * Débloque le succès pour le compte, comme le service l'a fait avant le
 * correctif : la ligne, datée, et l'annonce, par une vraie notification.
 */
function serieMigreeDebloquer(User $compte, string $slug, string $le = '2026-10-07 19:00:00'): void
{
    $succes = Achievement::query()->where('slug', $slug)->firstOrFail();

    $compte->achievements()->attach($succes->id, ['achieved_at' => Carbon::parse($le)]);
    $compte->notify(new AchievementUnlocked($succes));
}

/**
 * Les noms courts des succès du compte, avec leur date.
 *
 * @return array<string, string>
 */
function serieMigreeSucces(User $compte): array
{
    /** @var array<string, string> $succes */
    $succes = DB::table('user_achievements')
        ->join('achievements', 'achievements.id', '=', 'user_achievements.achievement_id')
        ->where('user_achievements.user_id', $compte->id)
        ->orderBy('achievements.slug')
        ->pluck('user_achievements.achieved_at', 'achievements.slug')
        ->all();

    return $succes;
}

/**
 * Les noms courts des succès que les notifications du compte annoncent.
 *
 * @return list<string>
 */
function serieMigreeAnnonces(User $compte): array
{
    $identifiants = $compte->notifications()
        ->where('type', AchievementUnlocked::class)
        ->get()
        ->map(fn ($notification): mixed => $notification->data['achievement_id'] ?? null)
        ->all();

    /** @var list<string> $slugs */
    $slugs = Achievement::query()->whereIn('id', $identifiants)->orderBy('slug')->pluck('slug')->all();

    return $slugs;
}

it('refait la série et la dernière séance depuis les seules séances faites', function (): void {
    $compte = User::factory()->create();
    serieMigreeSeances(
        $compte,
        faites: ['2026-10-01 10:00:00', '2026-10-02 10:00:00'],
        vides: ['2026-10-03 10:00:00', '2026-10-04 10:00:00'],
        enCours: ['2026-10-05 10:00:00'],
    );
    serieMigreePoser($compte, '2026-10-05 10:00:00', 5, 5);

    serieMigreeMigrer();

    expect(serieMigreeStockee($compte))->toBe(['2026-10-02 10:00:00', 2, 2]);
});

it('remet à zéro la série d’un compte qui n’a aucune séance faite', function (): void {
    $compte = User::factory()->create();
    serieMigreeSeances($compte, vides: ['2026-10-05 10:00:00', '2026-10-06 10:00:00', '2026-10-07 10:00:00']);
    serieMigreePoser($compte, '2026-10-07 10:00:00', 3, 3);

    serieMigreeMigrer();

    expect(serieMigreeStockee($compte))->toBe([null, 0, 0]);
});

it('retire les succès de série que les séances faites ne soutiennent pas, avec leurs annonces lues ou non, sans rien annoncer', function (): void {
    Achievement::factory()->create(['type' => 'streak', 'threshold' => 2, 'slug' => 'streak-2']);
    Achievement::factory()->create(['type' => 'streak', 'threshold' => 4, 'slug' => 'streak-4']);

    $compte = User::factory()->create();
    serieMigreeSeances($compte, faites: ['2026-10-05 10:00:00', '2026-10-06 10:00:00'], vides: ['2026-10-07 10:00:00', '2026-10-08 10:00:00']);
    serieMigreePoser($compte, '2026-10-08 10:00:00', 4, 4);

    serieMigreeDebloquer($compte, 'first-workout', '2026-10-05 11:00:00');
    serieMigreeDebloquer($compte, 'streak-2', '2026-10-06 11:00:00');
    serieMigreeDebloquer($compte, 'streak-3', '2026-10-07 11:00:00');
    serieMigreeDebloquer($compte, 'streak-4', '2026-10-08 11:00:00');

    // L'annonce de « Série de 3 » a été lue ; celle de la série de quatre non.
    // Celle d'un record, d'un autre type, porte le même identifiant par hasard
    // et reste.
    $serieDeTrois = Achievement::query()->where('slug', 'streak-3')->value('id');
    $compte->notifications()->where('data->achievement_id', $serieDeTrois)->firstOrFail()->markAsRead();
    $compte->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => PersonalRecordAchieved::class,
        'data' => ['achievement_id' => $serieDeTrois],
    ]);

    Notification::fake();

    serieMigreeMigrer();

    expect(serieMigreeSucces($compte))->toBe(['first-workout' => '2026-10-05 11:00:00', 'streak-2' => '2026-10-06 11:00:00'])
        ->and(serieMigreeAnnonces($compte))->toBe(['first-workout', 'streak-2'])
        ->and($compte->notifications()->where('type', PersonalRecordAchieved::class)->count())->toBe(1)
        ->and(serieMigreeStockee($compte))->toBe(['2026-10-06 10:00:00', 2, 2]);

    Notification::assertNothingSent();
});

it('garde « Série de 3 » que trois jours faits soutiennent, à sa date, même anciens', function (): void {
    $compte = User::factory()->create();
    serieMigreeSeances(
        $compte,
        faites: ['2026-03-01 10:00:00', '2026-03-02 10:00:00', '2026-03-03 10:00:00', '2026-10-06 10:00:00'],
        vides: ['2026-10-07 10:00:00'],
    );
    serieMigreePoser($compte, '2026-10-07 10:00:00', 2, 3);
    serieMigreeDebloquer($compte, 'streak-3', '2026-03-03 11:00:00');

    serieMigreeMigrer();

    expect(serieMigreeSucces($compte))->toBe(['streak-3' => '2026-03-03 11:00:00'])
        ->and(serieMigreeAnnonces($compte))->toBe(['streak-3'])
        ->and(serieMigreeStockee($compte))->toBe(['2026-10-06 10:00:00', 1, 3]);
});

it('ne touche pas un autre compte', function (): void {
    $aCorriger = User::factory()->create();
    serieMigreeSeances($aCorriger, vides: ['2026-10-05 10:00:00', '2026-10-06 10:00:00', '2026-10-07 10:00:00']);
    serieMigreePoser($aCorriger, '2026-10-07 10:00:00', 3, 3);
    serieMigreeDebloquer($aCorriger, 'streak-3');

    $juste = User::factory()->create();
    serieMigreeSeances($juste, faites: ['2026-10-05 10:00:00', '2026-10-06 10:00:00', '2026-10-07 10:00:00']);
    serieMigreePoser($juste, '2026-10-07 10:00:00', 3, 3);
    serieMigreeDebloquer($juste, 'streak-3');
    $miseAJour = DB::table('users')->where('id', $juste->id)->value('updated_at');

    serieMigreeMigrer();

    expect(serieMigreeStockee($aCorriger))->toBe([null, 0, 0])
        ->and(serieMigreeSucces($aCorriger))->toBe([])
        ->and(serieMigreeStockee($juste))->toBe(['2026-10-07 10:00:00', 3, 3])
        ->and(serieMigreeSucces($juste))->toBe(['streak-3' => '2026-10-07 19:00:00'])
        ->and(serieMigreeAnnonces($juste))->toBe(['streak-3'])
        ->and(DB::table('users')->where('id', $juste->id)->value('updated_at'))->toBe($miseAJour);
});

it('oublie le dernier badge non lu que le cache gardait pour la modale de célébration', function (): void {
    $compte = User::factory()->create();
    serieMigreeSeances($compte, vides: ['2026-10-05 10:00:00', '2026-10-06 10:00:00', '2026-10-07 10:00:00']);
    serieMigreeDebloquer($compte, 'streak-3');

    $notifications = app(NotificationService::class);

    expect($notifications->getLatestAchievement($compte))->not->toBeNull()
        ->and($notifications->getUnreadCount($compte))->toBe(1);

    serieMigreeMigrer();

    expect($notifications->getLatestAchievement($compte))->toBeNull()
        ->and($notifications->getUnreadCount($compte))->toBe(0);
});

it('se rejoue sans rien changer de plus', function (): void {
    $compte = User::factory()->create();
    serieMigreeSeances($compte, faites: ['2026-10-05 10:00:00'], vides: ['2026-10-06 10:00:00', '2026-10-07 10:00:00']);
    serieMigreePoser($compte, '2026-10-07 10:00:00', 3, 3);
    serieMigreeDebloquer($compte, 'first-workout', '2026-10-05 11:00:00');
    serieMigreeDebloquer($compte, 'streak-3');

    serieMigreeMigrer();
    $apresLePremier = [serieMigreeStockee($compte), serieMigreeSucces($compte), serieMigreeAnnonces($compte)];

    serieMigreeMigrer();

    expect($apresLePremier)->toBe([['2026-10-05 10:00:00', 1, 1], ['first-workout' => '2026-10-05 11:00:00'], ['first-workout']])
        ->and([serieMigreeStockee($compte), serieMigreeSucces($compte), serieMigreeAnnonces($compte)])->toBe($apresLePremier);
});
