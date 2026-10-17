<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Achievement;
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
use App\Notifications\AchievementUnlocked;
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
 * `contrat-des-pages-gardees.json` pour la version courante. Il la relève
 * telle qu'une copie la garde : sans les props que `PROPS_JAMAIS_GARDEES`
 * nomme (le dernier poids et les objectifs de l'accueil), qu'aucune copie ne
 * porte. Une liste vide s'y lit `liste vide`, et un champ toujours nul
 * `null` : ce qu'ils contiendraient n'est pas couvert, et les données du
 * compte de référence doivent les remplir ; un champ que le compte ne peut
 * pas remplir se nomme, avec sa raison, dans `contratDesPagesToujoursNuls()`.
 * Les clés faites d'identifiants ou de dates se lisent comme une table
 * (`{}`), une clé absente d'une partie des éléments d'une liste comme
 * `absent`.
 *
 * La copie de la séance ne vient pas que de sa page : la page de séance y
 * verse sa copie locale, où entrent les données que ses écritures de l'API
 * rendent (une série créée ou modifiée, un exercice ajouté, et leurs rejeux
 * par la file), d'autres ressources que la page d'Inertia (`SetResource`,
 * `WorkoutLineResource`, `ExerciseResource`, `PersonalRecordResource`). Leur
 * forme se relève donc aussi, sous `Workouts/Show (écritures de la séance en
 * cours)`, et une ressource qui change fait monter la version comme une prop.
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
 * créées par l'API (clé d'idempotence) dans chacune, une note de séance et de
 * ligne, une série de cardio (distance et durée), un record dans la séance en
 * cours, un exercice au repos réglé, un avatar et un badge à célébrer, plus
 * un objectif et une pesée (que l'accueil rend, et qu'aucune copie ne garde),
 * et ce que lisent les outils : de quoi remplir chaque liste et chaque champ
 * des pages gardées.
 *
 * @return array{compte: User, enCours: Workout, close: Workout}
 */
function contratDesPagesCompte(): array
{
    fake()->seed(2048);

    $compte = User::factory()->create(['name' => 'Camille Contrat', 'default_rest_time' => 90, 'avatar' => 'https://avatars.example.org/camille.png']);
    $developpe = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Développé couché', 'type' => 'strength', 'category' => 'Pectoraux', 'default_rest_time' => 150]);
    $traction = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Traction', 'type' => 'strength', 'category' => 'Dos', 'default_rest_time' => 120]);
    $rameur = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Rameur', 'type' => 'cardio', 'category' => 'Cardio']);
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
            $ligne = WorkoutLine::factory()->create([
                'workout_id' => $seance->id,
                'exercise_id' => $exercice->id,
                'order' => $rang,
                'idempotency_key' => $cle,
                'notes' => $rang === 0 ? 'Prise serrée' : null,
            ]);
            Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 80 + $joursAvant, 'reps' => 8, 'idempotency_key' => $cle]);
            Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 82.5, 'reps' => 6]);
        }

        if ($rangDeLaSeance === 0) {
            $ligneDeCardio = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $rameur->id, 'order' => 2]);
            Set::factory()->create(['workout_line_id' => $ligneDeCardio->id, 'weight' => null, 'reps' => null, 'distance_km' => 5, 'duration_seconds' => 1260]);
        }

        $closes[] = $seance;
    }

    $enCours = Workout::factory()->for($compte)->create(['name' => 'Séance du jour', 'started_at' => now()->subMinutes(30), 'notes' => 'Épaule à ménager']);
    $ligne = WorkoutLine::factory()->create([
        'workout_id' => $enCours->id,
        'exercise_id' => $developpe->id,
        'order' => 0,
        'idempotency_key' => '6d2f1c5e-8a4b-4f0e-9b7d-2048c0de0002',
        'notes' => 'Prise large',
    ]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 95, 'reps' => 5, 'idempotency_key' => '6d2f1c5e-8a4b-4f0e-9b7d-2048c0de0002']);
    Set::factory()->naPasEteFaite()->create(['workout_line_id' => $ligne->id, 'weight' => 85, 'reps' => 5]);
    $ligneDeCardio = WorkoutLine::factory()->create(['workout_id' => $enCours->id, 'exercise_id' => $rameur->id, 'order' => 1]);
    Set::factory()->create(['workout_line_id' => $ligneDeCardio->id, 'weight' => null, 'reps' => null, 'distance_km' => 2.5, 'duration_seconds' => 600]);
    $compte->notify(new AchievementUnlocked(Achievement::factory()->create(['name' => 'Premier pas', 'icon' => '👣', 'description' => 'Ta première séance.'])));

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
 * Les données qu'une écriture de l'API rend (`data`), objets JSON gardés
 * comme objets, comme la page de séance l'envoie.
 *
 * @param  array<array-key, mixed>  $corps
 */
function contratDesPagesEcriture(User $compte, string $methode, string $chemin, array $corps, ?string $cleDIdempotence = null): stdClass
{
    /** @var TestResponse<Response> $reponse */
    $reponse = actingAs($compte)->withHeaders(array_filter([
        'X-Requested-With' => 'XMLHttpRequest',
        'Idempotency-Key' => $cleDIdempotence,
    ], static fn (?string $valeur): bool => $valeur !== null))->json($methode, $chemin, $corps);

    $reponse->assertSuccessful();
    $contenu = json_decode((string) $reponse->getContent(), flags: JSON_THROW_ON_ERROR);
    assert($contenu instanceof stdClass && ($contenu->data ?? null) instanceof stdClass, "`{$methode} {$chemin}` ne rend pas de données.");

    return $contenu->data;
}

/**
 * Ce que les écritures de la page de séance rendent, et que la page verse
 * dans sa copie locale de la séance, d'où l'instantané de la copie se prend
 * (`useCopieDeLaSeance`) : la série créée (`adopterLaSerie`, force et cardio,
 * cochée ou non), la série modifiée (`useSaisieDeSerie`,
 * `useValidationDeSerie`), l'exercice ajouté (`adopterLaLigne`, son exercice
 * et sa recommandation compris), et la création rejouée d'un exercice, que le
 * serveur reconnaît à sa clé d'idempotence et rend avec ses séries. Le vidage
 * de la file rend les mêmes (`rejeu.data`). Une ressource de l'API qui change
 * change donc la forme des copies de séance, comme une prop de la page.
 *
 * Après les pages : ces écritures changent la séance en cours du compte.
 */
function contratDesPagesReponsesDeLaSeance(User $compte, Workout $enCours): stdClass
{
    $lignes = $enCours->workoutLines()->with(['exercise', 'sets'])->orderBy('order')->get();
    $force = $lignes->firstOrFail(fn (WorkoutLine $ligne): bool => $ligne->exercise?->type === 'strength');
    $cardio = $lignes->firstOrFail(fn (WorkoutLine $ligne): bool => $ligne->exercise?->type === 'cardio');
    $nonFaite = $force->sets->firstOrFail(fn (Set $serie): bool => ! $serie->is_completed);
    $traction = Exercise::query()->where('user_id', $compte->id)->where('name', 'Traction')->firstOrFail();
    $creerUneLigne = fn (int $exercice, string $cle): stdClass => contratDesPagesEcriture($compte, 'POST', route('api.v1.workout-lines.store', absolute: false), ['workout_id' => $enCours->id, 'exercise_id' => $exercice], $cle);
    $creerUneSerie = fn (array $corps, string $cle): stdClass => contratDesPagesEcriture($compte, 'POST', route('api.v1.sets.store', absolute: false), $corps, $cle);
    $modifierUneSerie = fn (int $serie, array $corps): stdClass => contratDesPagesEcriture($compte, 'PATCH', route('api.v1.sets.update', ['set' => $serie], absolute: false), $corps);

    $lignesAjoutees = [
        $creerUneLigne($traction->id, '6d2f1c5e-8a4b-4f0e-9b7d-2048c0de0003'),
        $creerUneLigne($cardio->exercise_id, '6d2f1c5e-8a4b-4f0e-9b7d-2048c0de0004'),
    ];
    $seriesCreees = [
        $creerUneSerie(['workout_line_id' => $force->id, 'weight' => 100, 'reps' => 3, 'is_completed' => true], '6d2f1c5e-8a4b-4f0e-9b7d-2048c0de0005'),
        $creerUneSerie(['workout_line_id' => $lignesAjoutees[1]->id, 'distance_km' => 3, 'duration_seconds' => 900, 'is_completed' => false], '6d2f1c5e-8a4b-4f0e-9b7d-2048c0de0006'),
    ];

    return (object) ['props' => (object) [
        'POST /api/v1/sets' => $seriesCreees,
        'PATCH /api/v1/sets/{set}' => [
            $modifierUneSerie($nonFaite->id, ['weight' => 105]),
            $modifierUneSerie($nonFaite->id, ['is_completed' => true]),
            $modifierUneSerie($cardio->sets->firstOrFail()->id, ['duration_seconds' => 660]),
        ],
        'POST /api/v1/workout-lines' => [
            ...$lignesAjoutees,
            // Rejouées : le serveur reconnaît la clé, et rend la ligne qu'il a déjà, ses séries comprises.
            $creerUneLigne($force->exercise_id, (string) $force->idempotency_key),
            $creerUneLigne($cardio->exercise_id, '6d2f1c5e-8a4b-4f0e-9b7d-2048c0de0004'),
        ],
    ]];
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
 * page le demande (`X-Inertia-Partial-Data`), et versé dans ses props ; puis
 * telle qu'une copie la garde, sans les props que `PROPS_JAMAIS_GARDEES`
 * nomme pour son composant. Chacune doit être dans la réponse : une prop
 * renommée laisserait sinon passer la nouvelle dans les copies.
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

    foreach (PagesGardees::propsJamaisGardees()[$composant] ?? [] as $prop) {
        expect(property_exists($props, $prop))->toBeTrue("`PROPS_JAMAIS_GARDEES` nomme `{$prop}`, que `{$composant}` ne rend plus : nomme la prop qui l’a remplacée.");
        unset($props->{$prop});
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
    return array_all(
        array_keys(get_object_vars($objet)),
        fn (int|string $cle): bool => preg_match('/^(\d+|\d{4}-\d{2}(-\d{2})?)$/', (string) $cle) === 1,
    );
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
 * deux états, en cours et close, les autres une fois chacune ; puis celle de
 * ce que les écritures de la séance en cours rendent, que sa copie garde aussi.
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

    $formes['Workouts/Show (écritures de la séance en cours)'] = contratDesPagesForme(contratDesPagesReponsesDeLaSeance($compte, $enCours));

    ksort($formes, SORT_STRING);

    return $formes;
}

/**
 * Les champs que le compte de référence ne peut pas remplir, et pourquoi. Un
 * champ relevé `null` seulement n'a pas de forme : la garde ne verrait pas
 * qu'elle change. Un chemin seul vaut pour toutes les pages ; `page › chemin`
 * pour une seule.
 *
 * @return array<string, string>
 */
function contratDesPagesToujoursNuls(): array
{
    return [
        'flash.error' => 'aucun message sur une page qu’aucune écriture n’a précédée',
        'flash.success' => 'aucun message sur une page qu’aucune écriture n’a précédée',
        'pending_migrations' => 'nul hors de l’environnement local (`HandleInertiaRequests::pendingMigrationCount`)',
        'workouts.prev_page_url' => 'seule la première page de la liste des séances se garde',
        'workouts.next_page_url' => 'le paginateur de Laravel, sans page suivante pour le compte de référence (vingt séances par page)',
        'Workouts/Show (séance en cours) › workout.ended_at' => 'une séance en cours n’a pas de fin',
    ];
}

/**
 * Les chemins d'une forme relevés `null` seulement, sans les champs que
 * `contratDesPagesToujoursNuls()` nomme : `page › chemin`.
 *
 * @param  array<string, array{props: array<string, string>, differees: array<string, list<string>>}>  $formes
 * @return list<string>
 */
function contratDesPagesChampsSansForme(array $formes): array
{
    $admis = contratDesPagesToujoursNuls();
    $sansForme = [];

    foreach ($formes as $page => $forme) {
        foreach ($forme['props'] as $chemin => $types) {
            if ($types === 'null' && ! array_key_exists($chemin, $admis) && ! array_key_exists("{$page} › {$chemin}", $admis)) {
                $sansForme[] = "{$page} › {$chemin}";
            }
        }
    }

    return $sansForme;
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
            "La forme des props des pages gardées, ou de ce que les écritures de la séance rendent, a changé sans que `VERSION_DU_CONTRAT` monte. Une copie restée sur un appareil garde l’ancienne forme, et la page qui la relira compte sur celle-ci. Monte `VERSION_DU_CONTRAT` dans %s (les copies de l’ancienne version s’oublient), puis refige la forme par `CONTRAT_DES_PAGES_GARDEES=reecrire vendor/bin/pest %s`.\n%s",
            PagesGardees::MODULE,
            'tests/Feature/HorsLigne/ContratDesPagesGardeesTest.php',
            implode("\n", $ecarts),
        ));
});

it('ne relève aucun champ nul seulement, hors de ceux que le compte de référence ne peut pas remplir', function (): void {
    $sansForme = contratDesPagesChampsSansForme(contratDesPagesFormes());

    expect($sansForme)->toBe([], sprintf(
        "Ces champs valent `null` pour le compte de référence : leur forme n’est pas couverte, et un changement de type passerait sans que `VERSION_DU_CONTRAT` monte. Remplis-les dans `contratDesPagesCompte()`, ou nomme-les, avec leur raison, dans `contratDesPagesToujoursNuls()`.\n%s",
        implode("\n", $sansForme),
    ));
});

it('ne nomme parmi les champs toujours nuls que des champs que les pages gardées rendent nuls', function (): void {
    $formes = contratDesPagesFormes();
    $inconnus = [];

    foreach (array_keys(contratDesPagesToujoursNuls()) as $admis) {
        [$page, $chemin] = str_contains($admis, ' › ') ? explode(' › ', $admis, 2) : [null, $admis];
        $types = $page === null
            ? array_filter(array_map(static fn (array $forme): ?string => $forme['props'][$chemin] ?? null, $formes), is_string(...))
            : array_filter([$formes[$page]['props'][$chemin] ?? null], is_string(...));

        if (! in_array('null', $types, true)) {
            $inconnus[] = $admis;
        }
    }

    expect($inconnus)->toBe([], 'Ces champs ne sont plus nuls seulement, ou n’existent plus : retire-les de `contratDesPagesToujoursNuls()`.');
});
