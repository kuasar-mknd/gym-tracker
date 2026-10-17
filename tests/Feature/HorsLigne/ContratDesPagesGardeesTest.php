<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\IntervalTimer;
use App\Models\Plate;
use App\Models\Set;
use App\Models\User;
use App\Models\WarmupPreference;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\PagesGardees;

use function Pest\Laravel\actingAs;

/*
 * Le contrat des copies hors ligne (#2048) : la forme des props de chaque page
 * gardée, clés et types, sans aucune valeur.
 *
 * Une copie d'une page gardée vit plus longtemps que la construction qui l'a
 * écrite : elle porte `VERSION_DU_CONTRAT` (resources/js/Utils/pagesGardees.js),
 * pas l'empreinte des actifs, pour qu'un déploiement ne jette pas les copies
 * dont la salle a besoin. La page qui la relira, peut-être d'une construction
 * plus récente, compte sur cette forme : une prop renommée, retirée, ou dont le
 * type change, ferait planter une page montée depuis une copie restée sur
 * l'appareil. Une forme qui change exige donc que la version monte, et la
 * version qui monte fait oublier les copies de l'ancienne.
 *
 * Ce test relève la forme par de vraies requêtes d'Inertia, faites pour un
 * compte aux données tirées des factories (Faker à graine fixe, horloge
 * arrêtée), props différées comprises, et la compare à celle que fige
 * `contrat-des-pages-gardees.json` pour la version courante. Une liste vide
 * s'y lit `liste vide` : ce qu'elle contiendrait n'est pas couvert, et les
 * données du compte de référence doivent la remplir. Les clés faites
 * d'identifiants ou de dates se lisent comme une table (`{}`), une clé absente
 * d'une partie des éléments d'une liste comme `absent`.
 *
 * Quand la forme change, à dessein : monter `VERSION_DU_CONTRAT`, puis
 * réécrire le fichier par
 * `CONTRAT_DES_PAGES_GARDEES=reecrire vendor/bin/pest tests/Feature/HorsLigne/ContratDesPagesGardeesTest.php`.
 * La réécriture refuse de figer une autre forme sous la même version.
 */

/**
 * Le fichier qui fige la forme, et la version qu'elle vaut.
 */
function contratDesPagesFichier(): string
{
    return __DIR__.'/contrat-des-pages-gardees.json';
}

/**
 * Le compte de référence et ses séances : la séance en cours et la séance
 * close qui porte ses records, chacune avec des exercices et des séries, une
 * faite et une non faite dans la séance en cours, une ligne et une série
 * créées par l'API (clé d'idempotence), plus un objectif, une pesée, et ce
 * que lisent les outils : de quoi remplir chaque liste des pages gardées.
 *
 * @return array{compte: User, enCours: Workout, close: Workout}
 */
function contratDesPagesCompte(): array
{
    fake()->seed(2048);

    $compte = User::factory()->create(['name' => 'Camille Contrat', 'default_rest_time' => 90]);
    $developpe = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Développé couché', 'type' => 'strength', 'category' => 'Pectoraux']);
    $traction = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Traction', 'type' => 'strength', 'category' => 'Dos']);
    $closes = [];

    foreach ([9, 5, 2] as $rangDeLaSeance => $joursAvant) {
        $seance = Workout::factory()->for($compte)->create([
            'name' => "Séance d’il y a {$joursAvant} jours",
            'started_at' => now()->subDays($joursAvant),
            'ended_at' => now()->subDays($joursAvant)->addHour(),
            'notes' => 'Bonne séance',
        ]);

        foreach ([$developpe, $traction] as $rang => $exercice) {
            $cle = $rangDeLaSeance === 0 && $rang === 0 ? '6d2f1c5e-8a4b-4f0e-9b7d-2048c0de0001' : null;
            $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id, 'order' => $rang, 'idempotency_key' => $cle]);
            Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 80 + $joursAvant, 'reps' => 8, 'idempotency_key' => $cle]);
            Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 82.5, 'reps' => 6]);
        }

        $closes[] = $seance;
    }

    $enCours = Workout::factory()->for($compte)->create(['name' => 'Séance du jour', 'started_at' => now()->subMinutes(30)]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $enCours->id, 'exercise_id' => $developpe->id, 'order' => 0]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 85, 'reps' => 5]);
    Set::factory()->naPasEteFaite()->create(['workout_line_id' => $ligne->id, 'weight' => 85, 'reps' => 5]);

    IntervalTimer::factory()->create(['user_id' => $compte->id, 'name' => 'Tabata']);
    Plate::factory()->create(['user_id' => $compte->id, 'weight' => 20, 'quantity' => 4]);
    WarmupPreference::factory()->create(['user_id' => $compte->id, 'bar_weight' => 20.0, 'rounding_increment' => 2.5]);
    Goal::factory()->create([
        'user_id' => $compte->id,
        'exercise_id' => $developpe->id,
        'title' => 'Développé à 100 kg',
        'type' => 'weight',
        'target_value' => 100,
        'start_value' => 70,
        'deadline' => now()->addMonths(2),
    ]);
    BodyMeasurement::factory()->create(['user_id' => $compte->id, 'weight' => 78.4, 'measured_at' => now()->subDay()->toDateString(), 'notes' => null]);

    return ['compte' => $compte->refresh(), 'enCours' => $enCours, 'close' => $closes[0]];
}

/**
 * La page d'Inertia qu'une visite reçoit, objets JSON gardés comme objets :
 * `{}` et `[]` ne se confondent pas.
 *
 * @param  array<string, string>  $enTetes
 */
function contratDesPagesVisite(User $compte, string $chemin, array $enTetes = []): stdClass
{
    /** @var TestResponse<Response> $reponse */
    $reponse = actingAs($compte)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Requested-With' => 'XMLHttpRequest',
        ...$enTetes,
    ])->get($chemin);

    $reponse->assertOk()->assertHeader('X-Inertia', 'true');
    $page = json_decode((string) $reponse->getContent(), flags: JSON_THROW_ON_ERROR);
    assert($page instanceof stdClass);

    return $page;
}

/**
 * Les props d'une page.
 */
function contratDesPagesProps(stdClass $page): stdClass
{
    $props = $page->props ?? null;
    assert($props instanceof stdClass, 'La page n’a pas de props.');

    return $props;
}

/**
 * Les groupes de props différées d'une page, chacun avec les noms de ses props.
 *
 * @return array<string, list<string>>
 */
function contratDesPagesGroupesDifferes(stdClass $page): array
{
    $groupes = [];

    foreach ((array) ($page->deferredProps ?? []) as $groupe => $noms) {
        $groupes[(string) $groupe] = array_values(array_filter((array) $noms, is_string(...)));
    }

    return $groupes;
}

/**
 * La page complète, puis chaque groupe de props différées, demandé comme la
 * page le demande (`X-Inertia-Partial-Data`), et versé dans ses props.
 */
function contratDesPagesPageEntiere(User $compte, string $chemin, string $composant): stdClass
{
    $page = contratDesPagesVisite($compte, $chemin);
    $props = contratDesPagesProps($page);

    expect($page->component ?? null)->toBe($composant, "`{$chemin}` ne rend pas le composant que `PAGES_GARDEES` attend.");

    foreach (contratDesPagesGroupesDifferes($page) as $groupe) {
        $partielle = contratDesPagesProps(contratDesPagesVisite($compte, $chemin, [
            'X-Inertia-Partial-Component' => $composant,
            'X-Inertia-Partial-Data' => implode(',', $groupe),
        ]));

        foreach ($groupe as $prop) {
            expect(property_exists($partielle, $prop))->toBeTrue("La prop différée `{$prop}` de `{$composant}` n’arrive pas.");
            $props->{$prop} = $partielle->{$prop};
        }
    }

    return $page;
}

/**
 * Le type d'une valeur JSON, tel que la page le lit.
 */
function contratDesPagesType(mixed $valeur): string
{
    return match (true) {
        $valeur === null => 'null',
        is_bool($valeur) => 'booléen',
        is_int($valeur), is_float($valeur) => 'nombre',
        is_string($valeur) => 'texte',
        is_array($valeur) => $valeur === [] ? 'liste vide' : 'liste',
        $valeur instanceof stdClass && get_object_vars($valeur) === [] => 'objet vide',
        $valeur instanceof stdClass && contratDesPagesEstUneTable($valeur) => 'table',
        default => 'objet',
    };
}

/**
 * Un objet dont chaque clé est un identifiant ou une date : une table, dont
 * les clés sont des valeurs et non des noms de champs.
 */
function contratDesPagesEstUneTable(stdClass $objet): bool
{
    foreach (array_keys(get_object_vars($objet)) as $cle) {
        if (preg_match('/^(\d+|\d{4}-\d{2}(-\d{2})?)$/', (string) $cle) !== 1) {
            return false;
        }
    }

    return true;
}

/**
 * Relève la forme d'une valeur sous son chemin : chaque chemin reçoit les
 * types qu'on y rencontre. Les éléments d'une liste se lisent sous `[]`, les
 * valeurs d'une table sous `{}`.
 *
 * @param  array<string, array<string, true>>  $forme
 */
function contratDesPagesReleve(mixed $valeur, string $chemin, array &$forme): void
{
    $type = contratDesPagesType($valeur);
    $forme[$chemin][$type] = true;

    if ($valeur instanceof stdClass) {
        foreach (get_object_vars($valeur) as $cle => $champ) {
            contratDesPagesReleve($champ, $type === 'table' ? "{$chemin}{}" : "{$chemin}.{$cle}", $forme);
        }

        return;
    }

    if (! is_array($valeur)) {
        return;
    }

    $champsDesElements = [];

    foreach ($valeur as $element) {
        if ($element instanceof stdClass && contratDesPagesType($element) === 'objet') {
            $champsDesElements += array_fill_keys(array_map(static fn (int|string $cle): string => (string) $cle, array_keys(get_object_vars($element))), true);
        }
    }

    foreach ($valeur as $element) {
        contratDesPagesReleve($element, "{$chemin}[]", $forme);

        if (! $element instanceof stdClass || contratDesPagesType($element) !== 'objet') {
            continue;
        }

        foreach (array_keys($champsDesElements) as $champ) {
            if (! property_exists($element, $champ)) {
                $forme["{$chemin}[].{$champ}"]['absent'] = true;
            }
        }
    }
}

/**
 * La forme d'une page : ses props, chemin par chemin, et ses groupes de props
 * différées.
 *
 * @return array{props: array<string, string>, differees: array<string, list<string>>}
 */
function contratDesPagesForme(stdClass $page): array
{
    $releve = [];

    foreach (get_object_vars(contratDesPagesProps($page)) as $nom => $valeur) {
        contratDesPagesReleve($valeur, (string) $nom, $releve);
    }

    ksort($releve, SORT_STRING);

    $props = [];

    foreach ($releve as $chemin => $types) {
        $noms = array_keys($types);
        sort($noms, SORT_STRING);
        $props[$chemin] = implode('|', $noms);
    }

    $differees = contratDesPagesGroupesDifferes($page);

    foreach ($differees as &$noms) {
        sort($noms, SORT_STRING);
    }

    unset($noms);
    ksort($differees, SORT_STRING);

    return ['props' => $props, 'differees' => $differees];
}

/**
 * La forme de chaque page gardée, pour le compte de référence : la séance en
 * deux états, en cours et close, les autres une fois chacune.
 *
 * @return array<string, array{props: array<string, string>, differees: array<string, list<string>>}>
 */
function contratDesPagesFormes(): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-12 18:04:00'));
    config(['webpush.vapid.public_key' => 'cle-publique-du-contrat']);

    ['compte' => $compte, 'enCours' => $enCours, 'close' => $close] = contratDesPagesCompte();
    $formes = [];

    foreach (PagesGardees::liste() as $page) {
        if ($page['composant'] !== 'Workouts/Show') {
            $formes[$page['composant']] = contratDesPagesForme(contratDesPagesPageEntiere($compte, $page['chemin'], $page['composant']));

            continue;
        }

        foreach (['séance en cours' => $enCours, 'séance close' => $close] as $etat => $seance) {
            $chemin = str_replace('{workout}', (string) $seance->id, $page['chemin']);
            $formes["{$page['composant']} ({$etat})"] = contratDesPagesForme(contratDesPagesPageEntiere($compte, $chemin, $page['composant']));
        }
    }

    ksort($formes, SORT_STRING);

    return $formes;
}

/**
 * Les écarts entre deux formes, une ligne par chemin qui diffère.
 *
 * @param  array<string, mixed>  $figee
 * @param  array<string, mixed>  $relevee
 * @return list<string>
 */
function contratDesPagesEcarts(array $figee, array $relevee): array
{
    $ecarts = [];

    foreach (array_unique([...array_keys($figee), ...array_keys($relevee)]) as $page) {
        $avant = $figee[$page] ?? null;
        $apres = $relevee[$page] ?? null;

        if ($avant === $apres) {
            continue;
        }

        if (! is_array($avant) || ! is_array($apres)) {
            $ecarts[] = $avant === null ? "{$page} : page ajoutée" : "{$page} : page retirée";

            continue;
        }

        foreach (['props', 'differees'] as $partie) {
            $champsAvant = (array) ($avant[$partie] ?? []);
            $champsApres = (array) ($apres[$partie] ?? []);

            foreach (array_unique([...array_keys($champsAvant), ...array_keys($champsApres)]) as $chemin) {
                $typeAvant = json_encode($champsAvant[$chemin] ?? '(absent)', JSON_UNESCAPED_UNICODE);
                $typeApres = json_encode($champsApres[$chemin] ?? '(absent)', JSON_UNESCAPED_UNICODE);

                if ($typeAvant !== $typeApres) {
                    $ecarts[] = "{$page} › {$chemin} : {$typeAvant} → {$typeApres}";
                }
            }
        }
    }

    return $ecarts;
}

it('garde la forme des props des pages gardées tant que la version du contrat ne monte pas', function (): void {
    $version = PagesGardees::versionDuContrat();
    $relevee = contratDesPagesFormes();
    $fichier = contratDesPagesFichier();
    $figee = is_file($fichier) ? json_decode((string) file_get_contents($fichier), true, flags: JSON_THROW_ON_ERROR) : null;
    $versionFigee = is_array($figee) && is_int($figee['version'] ?? null) ? $figee['version'] : null;
    /** @var array<string, mixed> $pagesFigees */
    $pagesFigees = is_array($figee) && is_array($figee['pages'] ?? null) ? $figee['pages'] : [];
    $ecarts = contratDesPagesEcarts($pagesFigees, $relevee);

    if (getenv('CONTRAT_DES_PAGES_GARDEES') === 'reecrire') {
        expect($versionFigee === $version && $ecarts !== [])->toBeFalse(
            "La forme a changé sous la même version ({$version}) : monte d’abord `VERSION_DU_CONTRAT` dans ".PagesGardees::MODULE.'.',
        );

        file_put_contents($fichier, json_encode(['version' => $version, 'pages' => $relevee], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return;
    }

    expect($versionFigee)->not->toBeNull(sprintf(
        '%s est introuvable ou illisible : écris-le par `CONTRAT_DES_PAGES_GARDEES=reecrire vendor/bin/pest %s`.',
        $fichier,
        'tests/Feature/HorsLigne/ContratDesPagesGardeesTest.php',
    ))
        ->and($versionFigee)->toBeLessThanOrEqual($version, "La version figée ({$versionFigee}) dépasse `VERSION_DU_CONTRAT` ({$version}) : une version ne redescend pas.")
        ->and($versionFigee)->toBe($version, sprintf(
            '`VERSION_DU_CONTRAT` est passée à %d : refige la forme par `CONTRAT_DES_PAGES_GARDEES=reecrire vendor/bin/pest %s`, et relis l’écart avant de l’accepter.',
            $version,
            'tests/Feature/HorsLigne/ContratDesPagesGardeesTest.php',
        ))
        ->and($ecarts)->toBe([], sprintf(
            "La forme des props des pages gardées a changé sans que `VERSION_DU_CONTRAT` monte. Une copie restée sur un appareil garde l’ancienne forme, et la page qui la relira compte sur celle-ci. Monte `VERSION_DU_CONTRAT` dans %s (les copies de l’ancienne version s’oublient), puis refige la forme par `CONTRAT_DES_PAGES_GARDEES=reecrire vendor/bin/pest %s`.\n%s",
            PagesGardees::MODULE,
            'tests/Feature/HorsLigne/ContratDesPagesGardeesTest.php',
            implode("\n", $ecarts),
        ));
});
