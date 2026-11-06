<?php

declare(strict_types=1);

use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\RecommendedValuesService;
use App\Services\Stats\BodyStatsService;
use App\Services\Stats\ClesDeStats;
use App\Services\Stats\StatsCacheManager;
use App\Services\Stats\WorkoutStatsService;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * Étape 6 de #2151 : une version de clés ne revient jamais.
 *
 * Statistiques, recommandations et listes d'exercices rangent leurs entrées
 * sous une version : invalider, c'est en changer, et les entrées d'avant
 * deviennent inatteignables. Ces versions étaient des compteurs, rangés sans
 * échéance dans le cache, que Redis évince comme le reste quand il manque de
 * place. Une version évincée repartait de zéro, puis remontait vers des
 * numéros déjà servis : une entrée encore vivante sous l'un d'eux, écrite
 * avant une invalidation, était resservie.
 *
 * Chaque version est désormais un ULID, tiré à neuf à chaque invalidation et
 * quand elle manque : aucune valeur ne revient. Chaque cas joue les deux
 * chemins par lesquels l'ancien compteur retombait sur une entrée périmée :
 * effacer la version puis invalider (le compteur remontait au numéro déjà
 * servi), effacer la version puis relire (il repartait de zéro).
 */

/**
 * Le cache perd une version, comme quand Redis l'évince pour faire de la
 * place, et la lecture suivante se fait dans une autre requête, qui n'en a
 * rien gardé (le mémo du cache ne vit qu'une requête ou un job).
 */
function evincerLaVersionDeCache(string $cleDeVersion): void
{
    expect(Cache::has($cleDeVersion))->toBeTrue("la version {$cleDeVersion} n'est pas en cache : l'effacer ne prouverait rien");

    Cache::forget($cleDeVersion);
    app()->forgetScopedInstances();
}

/**
 * Une séance terminée, écrite sans ses écouteurs : le test ne lit que
 * l'historique des durées, et invalide lui-même les statistiques.
 */
function seanceTermineePourLesVersions(User $user, string $debut): void
{
    Workout::withoutEvents(fn (): Workout => Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => $debut,
        'ended_at' => Carbon::parse($debut)->addHour(),
    ]));
}

/**
 * Une pesée, écrite sans ses écouteurs, que le test invalide lui-même.
 */
function peseePourLesVersions(User $user, string $jour, float $poids): void
{
    BodyMeasurement::withoutEvents(fn (): BodyMeasurement => BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'measured_at' => $jour,
        'weight' => $poids,
    ]));
}

/**
 * Une ligne de l'exercice dans une séance passée, et trois séries. Écrites en
 * masse, sans les écouteurs des séries (records, objectifs, succès), que le
 * test ne lit pas (`.ai/rules/tests.md`) ; il invalide à leur place, une fois
 * par série, comme l'écouteur de `Set::saved`.
 */
function seriesPassesPourLesVersions(User $user, Exercise $exercice, string $debut, float $poids): void
{
    $seance = Workout::withoutEvents(fn (): Workout => Workout::factory()->create(['user_id' => $user->id, 'started_at' => $debut]));
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
    $rangs = range(0, 2);

    Set::insert(array_map(fn (int $rang): array => [
        'workout_line_id' => $ligne->id,
        'user_id' => $user->id,
        'order' => $rang,
        'weight' => $poids,
        'reps' => 8,
        'is_warmup' => false,
        'is_completed' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $rangs));

    foreach ($rangs as $rang) {
        app(RecommendedValuesService::class)->invaliderPour($user->id);
    }
}

/**
 * Les passages d'`app/` qui comptent encore une version de clés de cache, ou
 * le disent : un appel à `increment()` ou `decrement()` du cache sur une clé
 * de version ou de révision, que Redis refuse sur un ULID ; un commentaire qui
 * annonce qu'on incrémente la version, et qui égarerait le prochain lecteur
 * vers l'ancien compteur. Chacun sous la forme `fichier:ligne`.
 *
 * @return list<string>
 */
function passagesQuiComptentUneVersionDeCache(): array
{
    $passages = [];
    $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));

    foreach ($fichiers as $fichier) {
        if (! $fichier instanceof SplFileInfo || $fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());
        $relatif = str_replace(base_path().'/', '', $fichier->getPathname());

        if (preg_match_all('/Cache::(?:memo\(\s*\)\s*->\s*)?(?:increment|decrement)\s*\(\s*["\'][^"\']*(?:version|revision)/i', $source, $appels, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($appels[0] as [, $position]) {
                $passages[] = $relatif.':'.(substr_count(substr($source, 0, $position), "\n") + 1);
            }
        }

        foreach (token_get_all($source) as $jeton) {
            if (is_array($jeton)
                && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)
                && preg_match('/incr[ée]ment\w*\s+(?:la|les|une|sa|leurs?)\s+(?:versions?|r[ée]visions?)\b/iu', $jeton[1], $phrase, PREG_OFFSET_CAPTURE) === 1) {
                $passages[] = $relatif.':'.($jeton[2] + substr_count(substr($jeton[1], 0, $phrase[0][1]), "\n"));
            }
        }
    }

    sort($passages);

    return $passages;
}

/**
 * Les noms de la liste d'exercices du compte, telle que le cache la sert.
 *
 * @return list<string>
 */
function nomsDeLaListePourLesVersions(User $user): array
{
    /** @var list<string> $noms */
    $noms = Exercise::enCachePourUtilisateur($user->id)->pluck('name')->values()->all();

    return $noms;
}

it('ne ressert jamais une statistique des séances d’avant une invalidation quand sa version est évincée', function (): void {
    $user = User::factory()->create();
    $statistiques = app(WorkoutStatsService::class);
    $invalidation = app(StatsCacheManager::class);
    $version = "stats-version.seances.{$user->id}";

    seanceTermineePourLesVersions($user, '2026-10-01 10:00:00');
    expect($statistiques->getDurationHistory($user))->toHaveCount(1);

    seanceTermineePourLesVersions($user, '2026-10-02 10:00:00');
    $invalidation->clearWorkoutRelatedStats($user);
    expect($statistiques->getDurationHistory($user))->toHaveCount(2);

    // L'ancien compteur repartait de zéro et remontait au numéro déjà servi.
    evincerLaVersionDeCache($version);
    seanceTermineePourLesVersions($user, '2026-10-03 10:00:00');
    $invalidation->clearWorkoutRelatedStats($user);
    expect($statistiques->getDurationHistory($user))->toHaveCount(3);

    // Et sans écriture, il retombait sur l'entrée de la version zéro.
    evincerLaVersionDeCache($version);
    expect($statistiques->getDurationHistory($user))->toHaveCount(3);
});

it('ne ressert jamais une statistique des mesures d’avant une invalidation quand sa version est évincée', function (): void {
    $user = User::factory()->create();
    $statistiques = app(BodyStatsService::class);
    $invalidation = app(StatsCacheManager::class);
    $version = "stats-version.mesures.{$user->id}";

    peseePourLesVersions($user, '2026-10-01', 80.0);
    expect((float) $statistiques->getLatestBodyMetrics($user)->latest_weight)->toBe(80.0);

    peseePourLesVersions($user, '2026-10-02', 79.0);
    $invalidation->clearBodyMeasurementStats($user);
    expect((float) $statistiques->getLatestBodyMetrics($user)->latest_weight)->toBe(79.0);

    evincerLaVersionDeCache($version);
    peseePourLesVersions($user, '2026-10-03', 78.0);
    $invalidation->clearBodyMeasurementStats($user);
    expect((float) $statistiques->getLatestBodyMetrics($user)->latest_weight)->toBe(78.0);

    evincerLaVersionDeCache($version);
    expect((float) $statistiques->getLatestBodyMetrics($user)->latest_weight)->toBe(78.0);
});

it('ne ressert jamais une recommandation d’avant une invalidation quand sa version est évincée', function (): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create();
    $seanceDuJour = Workout::withoutEvents(fn (): Workout => Workout::factory()->create(['user_id' => $user->id, 'started_at' => '2026-10-10 10:00:00']));
    $ligneDuJour = WorkoutLine::factory()->create(['workout_id' => $seanceDuJour->id, 'exercise_id' => $exercice->id]);
    $recommandations = app(RecommendedValuesService::class);
    $version = "recommended_values:version:{$user->id}";

    // Aucun historique : les valeurs par défaut, sous la première version.
    expect($recommandations->getRecommendedValues($ligneDuJour)['weight'])->toBe(0.0);

    seriesPassesPourLesVersions($user, $exercice, '2026-10-08 10:00:00', 50.0);
    expect($recommandations->getRecommendedValues($ligneDuJour)['weight'])->toBe(50.0);

    // Trois séries de plus ramenaient l'ancien compteur, reparti de zéro, au
    // numéro sous lequel 50 kg attendait encore.
    evincerLaVersionDeCache($version);
    seriesPassesPourLesVersions($user, $exercice, '2026-10-09 10:00:00', 80.0);
    expect($recommandations->getRecommendedValues($ligneDuJour)['weight'])->toBe(80.0);

    evincerLaVersionDeCache($version);
    expect($recommandations->getRecommendedValues($ligneDuJour)['weight'])->toBe(80.0);
});

it('ne ressert jamais une liste d’exercices d’avant une modification du catalogue quand sa révision est évincée', function (): void {
    $user = User::factory()->create();
    $version = 'exercises_catalogue_revision';

    expect(nomsDeLaListePourLesVersions($user))->not->toContain('Squat');

    $catalogue = Exercise::factory()->create(['user_id' => null, 'name' => 'Squat']);
    expect(nomsDeLaListePourLesVersions($user))->toContain('Squat');

    evincerLaVersionDeCache($version);
    $catalogue->update(['name' => 'Squat avant']);
    $apresLaModification = nomsDeLaListePourLesVersions($user);
    expect($apresLaModification)->toContain('Squat avant');
    expect($apresLaModification)->not->toContain('Squat');

    evincerLaVersionDeCache($version);
    $apresLaSecondeEviction = nomsDeLaListePourLesVersions($user);
    expect($apresLaSecondeEviction)->toContain('Squat avant');
    expect($apresLaSecondeEviction)->not->toContain('Squat');
});

it('remplace une version que l’application n’a pas tirée, sans jamais la reprendre dans une clé', function (Closure $cleDeVersion, Closure $cleDEntree, string $valeur): void {
    /**
     * @var Closure(User): string $cleDeVersion
     * @var Closure(User): string $cleDEntree
     */
    $user = User::factory()->create();
    Cache::forever($cleDeVersion($user), $valeur);

    $cle = $cleDEntree($user);
    $versionTiree = Cache::get($cleDeVersion($user));
    $versionTiree = is_string($versionTiree) ? $versionTiree : '';

    expect(Str::isUlid($versionTiree))->toBeTrue('la version n’a pas été remplacée par un ULID')
        ->and($cle)->toContain($versionTiree)
        ->and($cleDEntree($user))->toBe($cle);
})->with([
    'séances' => [
        fn (User $user): string => "stats-version.seances.{$user->id}",
        fn (User $user): string => ClesDeStats::seances($user, 'volume_history.20'),
    ],
    'mesures' => [
        fn (User $user): string => "stats-version.mesures.{$user->id}",
        fn (User $user): string => ClesDeStats::mesures($user, 'latest_metrics'),
    ],
    'recommandations' => [
        fn (User $user): string => "recommended_values:version:{$user->id}",
        fn (User $user): string => RecommendedValuesService::cleDeCache($user->id, 3, 9),
    ],
    'catalogue' => [
        fn (User $user): string => 'exercises_catalogue_revision',
        fn (User $user): string => Exercise::cleDeListe($user->id),
    ],
])->with([
    // Ce que Redis rend d'un compteur que l'ancien code incrémentait.
    'entier d’une release d’avant' => ['3'],
    'valeur illisible' => ['corrompue'],
]);

it('ne lit la version des statistiques qu’une fois par requête', function (): void {
    $user = User::factory()->create();
    app(StatsCacheManager::class)->clearWorkoutRelatedStats($user);
    app()->forgetScopedInstances();

    $lectures = [];
    Event::listen(RetrievingKey::class, function (RetrievingKey $lecture) use (&$lectures): void {
        $lectures[] = $lecture->key;
    });

    ClesDeStats::seances($user, 'volume_history.20');
    ClesDeStats::seances($user, 'duration_history.20');
    ClesDeStats::seances($user, 'muscle_dist.30');

    expect(array_count_values($lectures)["stats-version.seances.{$user->id}"] ?? 0)->toBe(1);
});

it('ne compte plus aucune version de clés de cache dans app/, ni ne le dit', function (): void {
    expect(passagesQuiComptentUneVersionDeCache())->toBe([], 'une version de clés de cache est un ULID tiré par VersionDeCache, jamais un compteur (#2151) : ces passages l’incrémentent encore, ou le disent');
});
