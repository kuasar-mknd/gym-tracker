<?php

declare(strict_types=1);

use App\Actions\Dashboard\FetchDashboardDataAction;
use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;

/**
 * Le témoin des requêtes SQL de l'accueil (#1315, #2148, étape 1).
 *
 * L'accueil part en deux requêtes : la page, avec les chiffres immédiats et
 * les props partagées, puis la prop différée `analyticalStats`, demandée par
 * la page dès son affichage. Rien ne comptait ni l'une ni l'autre : #1593 a
 * posé ses témoins sur la page des séances et sur la séance en cours, pas ici.
 *
 * Chaque compte est figé à la valeur mesurée le 2026-11-06, en égalité : un
 * compte qui monte est une requête de trop, un compte qui baisse est un gain à
 * consigner. L'étape 3b de #2148 (`auth` et `flash` partagés en fermetures)
 * a baissé celui de la prop différée, de douze à neuf ; l'étape 3a (les props
 * immédiates en fermetures), de neuf à trois : la requête ne rejoue plus les
 * chiffres immédiats, et le dernier cas, qui le constatait, est retourné.
 *
 * On compte des instructions et leur forme, jamais des lectures
 * `Handler_read` ni un plan (`.ai/rules/services.md`) : le même nombre tient
 * sur MySQL 8.4 et 9.7.2.
 */

/**
 * Un compte qui a de quoi remplir chaque carte de l'accueil : des séances
 * closes sur deux semaines, avec leurs lignes et leurs séries, des records,
 * des objectifs en cours et des pesées.
 */
function accueilBudgetSemerUnCompte(User $compte): void
{
    $exercices = Exercise::factory()->count(4)->create(['user_id' => null]);

    foreach (range(1, 6) as $joursEcoules) {
        $debut = now()->subDays($joursEcoules * 2)->setTime(18, 0);
        $seance = Workout::factory()->create([
            'user_id' => $compte->id,
            'started_at' => $debut,
            'ended_at' => $debut->copy()->addHour(),
        ]);

        foreach ($exercices->take(2) as $exercice) {
            $ligne = WorkoutLine::factory()->create([
                'workout_id' => $seance->id,
                'user_id' => $compte->id,
                'exercise_id' => $exercice->id,
                'workout_started_at' => $seance->started_at,
            ]);

            Set::factory()->count(3)->create([
                'workout_line_id' => $ligne->id,
                'is_completed' => true,
            ]);
        }
    }

    PersonalRecord::factory()->count(3)->create(['user_id' => $compte->id]);
    Goal::factory()->count(2)->sequence(['type' => 'weight'], ['type' => 'frequency'])->create(['user_id' => $compte->id]);
    BodyMeasurement::factory()->count(3)->sequence(
        ['measured_at' => now()->subDays(1)],
        ['measured_at' => now()->subDays(8)],
        ['measured_at' => now()->subDays(15)],
    )->create(['user_id' => $compte->id]);
}

/**
 * Les instructions SQL d'un geste, dans leur ordre, le cache vidé juste avant :
 * le compte « à froid », celui d'une première ouverture après une expiration.
 *
 * @param  Closure(): mixed  $geste
 * @return list<string>
 */
function accueilBudgetInstructionsDe(Closure $geste): array
{
    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $geste();
    } finally {
        DB::disableQueryLog();
    }

    return array_values(array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog()));
}

/**
 * La requête de la prop différée, telle que la page l'envoie : une visite
 * partielle qui ne demande qu'`analyticalStats`.
 *
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function accueilBudgetDemanderLaPropDifferee(User $compte, string $version): TestResponse
{
    // Les en-têtes passent à la seule requête : `withHeaders()` les garderait pour les suivantes.
    return actingAs($compte)->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'Dashboard',
        'X-Inertia-Partial-Data' => 'analyticalStats',
    ]);
}

/**
 * Un message d'échec qui dit quoi faire, puis les instructions comptées.
 *
 * @param  list<string>  $instructions
 */
function accueilBudgetMessage(string $quoi, array $instructions): string
{
    return "{$quoi} a changé de nombre d'instructions SQL (compte à froid, figé le 2026-11-06). "
        .'Une requête de plus est à expliquer ou à retirer ; une de moins, à consigner ici avec le nouveau compte, '
        ."en le disant dans la PR (#2148).\n".implode("\n", $instructions);
}

/**
 * Le compte semé, et la version d'actifs que la page porte : sans elle,
 * Inertia répond 409 à la requête partielle.
 *
 * Un premier passage, non compté, sur chacune des deux requêtes : ce que le
 * processus garde en mémoire d'une requête à l'autre (comme un worker Octane),
 * et que vider le cache n'efface pas, ne dépend ainsi pas des tests qui ont
 * tourné avant.
 *
 * @return array{compte: User, version: string}
 */
function accueilBudgetPreparer(): array
{
    $compte = User::factory()->create();
    accueilBudgetSemerUnCompte($compte);

    $page = actingAs($compte)->get(route('dashboard'))->assertOk()->viewData('page');
    $version = is_array($page) && is_string($page['version'] ?? null) ? $page['version'] : '';
    accueilBudgetDemanderLaPropDifferee($compte, $version)->assertOk();

    return ['compte' => $compte, 'version' => $version];
}

beforeEach(function (): void {
    // Un mercredi soir, loin de minuit et du lundi : la semaine et le mois de chaque carte ne bougent pas.
    $this->travelTo(Carbon::parse('2026-11-04 20:30:00'));
});

it('compte les instructions SQL de l’accueil chargé en entier', function (): void {
    ['compte' => $compte] = accueilBudgetPreparer();
    $reponse = null;
    $instructions = accueilBudgetInstructionsDe(function () use ($compte, &$reponse): void {
        $reponse = actingAs($compte)->get(route('dashboard'));
    });

    expect($reponse)->toBeInstanceOf(TestResponse::class);
    /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $reponse */
    $reponse->assertOk();

    // La page complète, rendue par le gabarit, sans la prop différée.
    expect((string) $reponse->getContent())->toContain('data-page="app"')
        ->and(count($instructions))->toBe(9, accueilBudgetMessage('L’accueil chargé en entier', $instructions));
});

it('compte à part les instructions SQL de sa prop différée', function (): void {
    ['compte' => $compte, 'version' => $version] = accueilBudgetPreparer();
    $reponse = null;
    $instructions = accueilBudgetInstructionsDe(function () use ($compte, $version, &$reponse): void {
        $reponse = accueilBudgetDemanderLaPropDifferee($compte, $version);
    });

    expect($reponse)->toBeInstanceOf(TestResponse::class);
    /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $reponse */
    $reponse->assertOk()
        ->assertJsonPath('component', 'Dashboard')
        ->assertJsonMissingPath('props.recentWorkouts');

    // Douze le 2026-11-06, neuf depuis que `auth` est une fermeture (#2148,
    // étape 3b) : la requête de la prop différée ne le demande pas, et ne lit
    // plus le compte des non-lus, le dernier badge ni la séance en cours.
    // Trois depuis que les chiffres immédiats sont des fermetures (étape 3a) :
    // elle ne relit plus la pesée, les séances, les records ni les objectifs.
    expect($reponse->json('props.analyticalStats'))->toBeArray()
        ->and(count($instructions))->toBe(3, accueilBudgetMessage('La prop différée de l’accueil', $instructions));
});

/*
 * Ce que l'étape 3a de #2148 a retiré : Inertia ne saute, dans une réponse
 * partielle, que les props encore à calculer, et le contrôleur étalait des
 * chiffres déjà calculés. La requête de la prop différée relisait donc chaque
 * chiffre immédiat avant de le jeter. Les chiffres immédiats sont désormais
 * des fermetures, qu'Inertia ne résout que pour une prop demandée : aucune de
 * leurs instructions ne part avec la prop différée. Elles sont résolues ici
 * une à une, comme Inertia le fait pour la page complète.
 */
it('ne rejoue pas les chiffres immédiats dans la requête de la prop différée', function (): void {
    ['compte' => $compte, 'version' => $version] = accueilBudgetPreparer();
    $chiffresImmediats = accueilBudgetInstructionsDe(function () use ($compte): void {
        foreach (app(FetchDashboardDataAction::class)->chiffresImmediats($compte) as $chiffre) {
            if ($chiffre instanceof Closure) {
                $chiffre();
            }
        }
    });
    $propDifferee = accueilBudgetInstructionsDe(
        fn (): TestResponse => accueilBudgetDemanderLaPropDifferee($compte, $version)->assertOk(),
    );

    expect($chiffresImmediats)->toHaveCount(6)
        ->and(array_values(array_intersect($chiffresImmediats, $propDifferee)))->toBe([]);
});
