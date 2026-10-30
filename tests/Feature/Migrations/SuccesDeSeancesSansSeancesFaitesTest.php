<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\User;
use App\Models\Workout;
use App\Notifications\AchievementUnlocked;
use App\Notifications\PersonalRecordAchieved;
use App\Services\NotificationService;
use Database\Seeders\AchievementSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Les succès de séances qu'un compte a obtenus avant #2110 comptaient toutes
 * ses séances, vides comprises. Le service ne retire jamais un succès :
 * `2026_10_30_132312_retirer_les_succes_de_seances_sans_seances_faites.php`
 * retire, une fois, ceux que les séances faites du compte ne soutiennent pas,
 * avec leurs notifications, et oublie le cache qui les montrait. La migration
 * n'écrit aucun schéma : elle se joue dans la transaction du test.
 */

beforeEach(function (): void {
    $this->seed(AchievementSeeder::class);
});

/**
 * Joue `up()` de la migration, comme `migrate`.
 */
function succesSansSeancesFaitesMigrer(): void
{
    $migration = require database_path('migrations/2026_10_30_132312_retirer_les_succes_de_seances_sans_seances_faites.php');
    assert($migration instanceof Migration && method_exists($migration, 'up'));

    $migration->up();
}

/**
 * Le succès du catalogue, par son nom court.
 */
function succesSansSeancesFaitesDuCatalogue(string $slug): Achievement
{
    return Achievement::query()->where('slug', $slug)->firstOrFail();
}

/**
 * Débloque le succès pour le compte, comme le service l'a fait avant le
 * correctif : la ligne, datée, et l'annonce, par une vraie notification.
 */
function succesSansSeancesFaitesDebloquer(User $compte, string $slug, string $le = '2026-10-01 18:00:00'): void
{
    $succes = succesSansSeancesFaitesDuCatalogue($slug);

    $compte->achievements()->attach($succes->id, ['achieved_at' => Carbon::parse($le)]);
    $compte->notify(new AchievementUnlocked($succes));
}

/**
 * Des séances du compte, écrites sans écouteurs : aucun recalcul ne
 * débloque rien avant la migration.
 */
function succesSansSeancesFaitesSeances(User $compte, int $faites, int $vides): void
{
    Workout::withoutEvents(function () use ($compte, $faites, $vides): void {
        Workout::factory()->count($faites)->faite()->create(['user_id' => $compte->id]);
        Workout::factory()->count($vides)->create(['user_id' => $compte->id, 'ended_at' => now()]);
    });
}

/**
 * Les noms courts des succès du compte.
 *
 * @return list<string>
 */
function succesSansSeancesFaitesDuCompte(User $compte): array
{
    /** @var list<string> $slugs */
    $slugs = $compte->achievements()->orderBy('slug')->pluck('slug')->all();

    return $slugs;
}

/**
 * Les noms courts des succès que les notifications du compte annoncent.
 *
 * @return list<string>
 */
function succesSansSeancesFaitesAnnonces(User $compte): array
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

it('retire les succès de séances d’un compte sans séance faite, avec leurs notifications lues ou non', function (): void {
    $compte = User::factory()->create();
    succesSansSeancesFaitesSeances($compte, faites: 0, vides: 3);

    succesSansSeancesFaitesDebloquer($compte, 'first-workout');
    succesSansSeancesFaitesDebloquer($compte, 'week-warrior');
    succesSansSeancesFaitesDebloquer($compte, 'heavy-lifter-100');

    // L'annonce de « Première Séance » a été lue ; celle d'un record, d'un
    // autre type, porte le même identifiant par hasard et reste.
    $compte->notifications()
        ->where('data->achievement_id', succesSansSeancesFaitesDuCatalogue('first-workout')->id)
        ->firstOrFail()
        ->markAsRead();
    $compte->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => PersonalRecordAchieved::class,
        'data' => ['achievement_id' => succesSansSeancesFaitesDuCatalogue('first-workout')->id],
    ]);

    succesSansSeancesFaitesMigrer();

    expect(succesSansSeancesFaitesDuCompte($compte))->toBe(['heavy-lifter-100'])
        ->and(succesSansSeancesFaitesAnnonces($compte))->toBe(['heavy-lifter-100'])
        ->and($compte->notifications()->where('type', '!=', AchievementUnlocked::class)->count())->toBe(1);
});

it('garde les succès que les séances faites soutiennent, à leur date, et retire ceux au-delà', function (): void {
    $compte = User::factory()->create();
    succesSansSeancesFaitesSeances($compte, faites: 3, vides: 7);

    succesSansSeancesFaitesDebloquer($compte, 'first-workout', '2026-09-01 08:00:00');
    succesSansSeancesFaitesDebloquer($compte, 'week-warrior', '2026-09-02 08:00:00');
    succesSansSeancesFaitesDebloquer($compte, 'consistency-king', '2026-09-03 08:00:00');

    succesSansSeancesFaitesMigrer();

    expect(succesSansSeancesFaitesDuCompte($compte))->toBe(['first-workout', 'week-warrior'])
        ->and(succesSansSeancesFaitesAnnonces($compte))->toBe(['first-workout', 'week-warrior'])
        ->and(DB::table('user_achievements')->where('user_id', $compte->id)->orderBy('achieved_at')->pluck('achieved_at')->all())
        ->toBe(['2026-09-01 08:00:00', '2026-09-02 08:00:00']);
});

it('ne touche pas aux succès d’un autre compte', function (): void {
    $sansSeance = User::factory()->create();
    $autre = User::factory()->create();
    succesSansSeancesFaitesSeances($autre, faites: 1, vides: 0);

    succesSansSeancesFaitesDebloquer($sansSeance, 'first-workout');
    succesSansSeancesFaitesDebloquer($autre, 'first-workout');

    succesSansSeancesFaitesMigrer();

    expect(succesSansSeancesFaitesDuCompte($sansSeance))->toBe([])
        ->and(succesSansSeancesFaitesDuCompte($autre))->toBe(['first-workout'])
        ->and(succesSansSeancesFaitesAnnonces($autre))->toBe(['first-workout']);
});

it('oublie le dernier badge non lu que le cache gardait pour la modale de célébration', function (): void {
    $compte = User::factory()->create();
    succesSansSeancesFaitesDebloquer($compte, 'first-workout');

    $notifications = app(NotificationService::class);

    expect($notifications->getLatestAchievement($compte))->not->toBeNull()
        ->and($notifications->getUnreadCount($compte))->toBe(1);

    succesSansSeancesFaitesMigrer();

    expect($notifications->getLatestAchievement($compte))->toBeNull()
        ->and($notifications->getUnreadCount($compte))->toBe(0);
});

it('se rejoue sans rien retirer de plus', function (): void {
    $compte = User::factory()->create();
    succesSansSeancesFaitesSeances($compte, faites: 1, vides: 2);
    succesSansSeancesFaitesDebloquer($compte, 'first-workout');
    succesSansSeancesFaitesDebloquer($compte, 'week-warrior');

    succesSansSeancesFaitesMigrer();
    $apresLePremier = [succesSansSeancesFaitesDuCompte($compte), succesSansSeancesFaitesAnnonces($compte)];

    succesSansSeancesFaitesMigrer();

    expect($apresLePremier)->toBe([['first-workout'], ['first-workout']])
        ->and([succesSansSeancesFaitesDuCompte($compte), succesSansSeancesFaitesAnnonces($compte)])->toBe($apresLePremier);
});
