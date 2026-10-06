<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Une valeur dérivée stockée n'acquiert pas d'écrivain en douce.
 *
 * Le modèle cible de la refonte (#1513) classe chaque valeur en fait,
 * projection, référence ou intention. Une projection se déduit des faits : elle
 * ne se stocke pas, ou alors comme un cache assumé, avec UN SEUL
 * reconstructeur. L'application d'aujourd'hui en est loin : ses valeurs dérivées
 * sont stockées puis entretenues par ajustements, depuis plusieurs endroits, et
 * chaque endroit de plus est une façon de plus de diverger.
 *
 * La refonte n'est pas pour maintenant. Décision du propriétaire du dépôt :
 * plutôt que de trancher dès aujourd'hui le reconstructeur de chaque valeur,
 * on FIGE ses écrivains actuels, et ceux qui sont plusieurs portent leur dette
 * par écrit. La dette ne grandit plus ; elle se résorbera domaine par domaine.
 *
 * La liste vit ici, dans `projectionsLInventaire()`. Deux contrôles la tiennent :
 *
 * - un endroit qui écrit l'une de ces valeurs sans y figurer fait échouer la
 *   suite. Passer par l'écrivain existant ; ou, si un nouvel écrivain est
 *   vraiment nécessaire, l'ajouter à la liste avec sa raison — il devient une
 *   dette nommée, décidée et non glissée ;
 * - un écrivain listé qui n'écrit plus la valeur fait échouer aussi : une liste
 *   qui déborde du code couvrirait d'avance un écrivain futur au même endroit.
 *
 * Ce que la garde reconnaît comme une écriture, méthode par méthode, dans app/
 * et routes/ (commentaires écartés) :
 *
 * - une affectation de propriété (`->colonne =`, `+=`, `??=`, `++`, `--`) ;
 * - une affectation d'indice littéral (`$donnees['colonne'] = …`), dans une
 *   méthode qui écrit aussi en base ou qui prépare un formulaire Filament
 *   (`mutateFormData…`) : ailleurs, c'est un tableau de réponse ;
 * - le nom de la colonne en argument d'un appel qui écrit (`update`, `fill`,
 *   `forceFill`, `create`, `insert`, `upsert`, `increment`, `setAttribute`…) ou
 *   d'un `new` sur un modèle de app/Models, y compris à travers un tableau
 *   littéral ;
 * - un champ de formulaire Filament à son nom, sauf `->dehydrated(false)` ou
 *   `->disabled()` sans `->dehydrated()` ;
 * - une règle de validation à son nom, dans `rules()` d'une requête ou dans
 *   `validate()` : c'est la porte par laquelle le client fournit la valeur ;
 * - une chaîne SQL brute qui met à jour, insère ou supprime dans la table ;
 * - pour une table entière (records, succès), une chaîne qui écrit à partir
 *   du modèle (`Modele::…->delete()`), de `new Modele`, de `DB::table('…')` ou
 *   d'une relation (`->personalRecords()->create()`, `->achievements()->attach()`) ;
 * - pour une copie dénormalisée au nom commun (`user_id`), l'affectation dans
 *   le fichier du modèle, la même chaîne quand l'appel qui écrit nomme la
 *   colonne, ou une insertion en masse dans une méthode qui la nomme ;
 * - pour une valeur en cache, un appel qui écrit le cache dans une méthode qui
 *   construit la clef.
 *
 * Elle est incomplète, comme toute garde qui lit le texte au lieu d'exécuter le
 * code. Elle ne voit pas :
 *
 * - un nom de colonne qui n'est pas littéral (`$modele->{$colonne} = …`,
 *   `update([$colonne => …])`, `fill($request->validated())` quand aucune règle
 *   de validation ne nomme la colonne) ;
 * - une instance déjà chargée puis modifiée par une colonne au nom commun
 *   (`$record->value = …; $record->save()`), une requête rangée dans une
 *   variable avant son `->delete()`, ou un `->each->delete()` ;
 * - une copie de `user_id` affectée hors du fichier de son modèle
 *   (`$ligne->user_id = …`) : le nom est trop commun pour savoir quelle table
 *   il vise ;
 * - du SQL assemblé par concaténation, ou dont la table et la colonne sont
 *   dans deux chaînes différentes ;
 * - ce que la base écrit elle-même : `ON DELETE SET NULL` détache un record
 *   quand sa série disparaît, `ON DELETE CASCADE` emporte des lignes ;
 * - le code hors de app/ et de routes/ : migrations de données, seeders,
 *   fabriques et tests écrivent ces valeurs à bon droit ;
 * - un second calcul ajouté DANS une méthode déjà listée : la garde compte
 *   les écrivains par méthode, pas par instruction.
 *
 * Elle peut aussi se tromper dans l'autre sens, sur un nom qu'elle prend pour
 * une colonne d'ici : une colonne homonyme ajoutée à une autre table, une
 * propriété homonyme d'un objet qui n'est pas un modèle, un champ de filtre
 * Filament ou une règle de validation de filtre qui porte ce nom, la relation
 * `users()` d'un autre modèle que les succès. Le remède est de renommer, ou
 * d'affiner la garde — pas d'ajouter à la liste un écrivain qui n'en est pas un.
 *
 * Hors de l'inventaire, à dessein : les calculs CONSERVÉS (le score Wilks, les
 * macros d'un calcul) sont des faits datés, écrits une fois et jamais refaits ;
 * les entrées de cache qu'un seul `Cache::remember()` écrit ont par
 * construction un seul reconstructeur (les statistiques sont tenues à une clef
 * par méthode par le dernier test) ; les tables du framework et des paquets
 * (journaux, files, sessions) ne sont pas des projections du domaine.
 */

/**
 * Une projection de l'inventaire, avec ses valeurs par défaut.
 *
 * @param  'colonne'|'copie'|'lignes'|'cache'  $genre  `colonne` : des colonnes au nom propre à leur table ; `copie` : une colonne au nom commun, recopiée d'une autre table ; `lignes` : une table entière ; `cache` : une famille de clefs de cache.
 * @param  array<string, array<string, list<string>>>  $ecrivains  Par écrivain (ce qu'il fait) : fichier => méthodes.
 * @param  list<string>  $colonnes
 * @param  list<string>  $relations  Les relations Eloquent qui rendent les lignes de la table.
 * @return array{genre: string, stockage: string, domaine: string, dette: string|null, ecrivains: array<string, array<string, list<string>>>, colonnes: list<string>, table: string, modele: string, fichierDuModele: string, relations: list<string>, marqueur: string}
 */
function projectionsDecrire(
    string $genre,
    string $stockage,
    string $domaine,
    array $ecrivains,
    ?string $dette = null,
    array $colonnes = [],
    string $table = '',
    string $modele = '',
    string $fichierDuModele = '',
    array $relations = [],
    string $marqueur = '',
): array {
    return [
        'genre' => $genre,
        'stockage' => $stockage,
        'domaine' => $domaine,
        'dette' => $dette,
        'ecrivains' => $ecrivains,
        'colonnes' => $colonnes,
        'table' => $table,
        'modele' => $modele,
        'fichierDuModele' => $fichierDuModele,
        'relations' => $relations,
        'marqueur' => $marqueur,
    ];
}

/**
 * Les valeurs dérivées stockées, et les écrivains qu'on leur connaît.
 *
 * Le domaine est celui de « Quatorze promesses », le modèle cible de #1513 ;
 * l'état visé est le même pour toutes : un seul reconstructeur, ou plus de
 * stockage du tout. Relevé dans le code le 2026-10-03.
 *
 * @return array<string, array{genre: string, stockage: string, domaine: string, dette: string|null, ecrivains: array<string, array<string, list<string>>>, colonnes: list<string>, table: string, modele: string, fichierDuModele: string, relations: list<string>, marqueur: string}>
 */
function projectionsLInventaire(): array
{
    return [
        'la série de jours' => projectionsDecrire(
            genre: 'colonne',
            stockage: 'users.current_streak, users.longest_streak, users.last_workout_at',
            domaine: 'La chaîne de jours',
            colonnes: ['current_streak', 'longest_streak', 'last_workout_at'],
            ecrivains: [
                'la reconstruction depuis les séances' => [
                    'app/Services/StreakService.php' => ['recalculerDepuisLesFaits'],
                ],
                'l’avance d’un cran à chaque séance enregistrée' => [
                    'app/Services/StreakService.php' => ['updateStreak', 'rememberIfMoreRecent', 'calculateNewStreak'],
                ],
            ],
            dette: 'Deux calculs de la même série : l’un repart des séances, l’autre ajuste la valeur stockée. '
                .'Une séance saisie après coup, déplacée (#1983) ou supprimée passe déjà par le premier ; la cible n’en garde qu’un.',
        ),
        'le volume d’une séance' => projectionsDecrire(
            genre: 'colonne',
            stockage: 'workouts.workout_volume',
            domaine: 'La mémoire vérifiable de l’entraînement',
            colonnes: ['workout_volume'],
            ecrivains: [
                'le recalcul à chaque série' => [
                    'app/Models/Workout.php' => ['recalculerLeVolume'],
                ],
                'la réparation par la commande de cohérence' => [
                    'app/Console/Commands/VerifyDataCoherence.php' => ['recalculerLesVolumes'],
                ],
            ],
            dette: '`app:verify-data-coherence --repair` refait la somme par sa propre requête au lieu d’appeler '
                .'`Workout::recalculerLeVolume()` : deux définitions du même volume, libres de diverger.',
        ),
        'l’avancement d’un objectif' => projectionsDecrire(
            genre: 'colonne',
            stockage: 'goals.current_value, goals.progress_pct, goals.completed_at',
            domaine: 'La promesse tenue à jour',
            colonnes: ['current_value', 'progress_pct', 'completed_at'],
            ecrivains: [
                'le recalcul depuis l’activité' => [
                    'app/Services/GoalService.php' => [
                        'syncGoals',
                        'updateProgressPercentage',
                        'updateWeightGoal',
                        'updateFrequencyGoal',
                        'updateVolumeGoal',
                        'updateMeasurementGoal',
                        'releverLaPartieDuCorps',
                        'checkCompletion',
                    ],
                ],
                'la saisie dans le panneau d’administration' => [
                    'app/Filament/Resources/Goals/Schemas/GoalForm.php' => ['getComponents'],
                ],
            ],
            dette: 'Le panneau laisse taper `current_value` et `completed_at` à la main, sans recalculer `progress_pct` : '
                .'la valeur saisie tient jusqu’au prochain recalcul, qui l’écrase.',
        ),
        'les records personnels' => projectionsDecrire(
            genre: 'lignes',
            stockage: 'la table personal_records',
            domaine: 'La mémoire vérifiable de l’entraînement',
            table: 'personal_records',
            modele: 'PersonalRecord',
            relations: ['personalRecords', 'personalRecord'],
            colonnes: ['secondary_value'],
            ecrivains: [
                'la montée à chaque série validée' => [
                    'app/Services/PersonalRecordService.php' => ['update'],
                ],
                'la reconstruction depuis les séries' => [
                    'app/Services/PersonalRecordService.php' => ['reconstruire'],
                ],
            ],
            dette: 'Un record monte par comparaison à la valeur stockée, et se reconstruit depuis les séries quand celle '
                .'qui le portait change ou disparaît. La base en détache aussi elle-même (`ON DELETE SET NULL`).',
        ),
        'les succès débloqués' => projectionsDecrire(
            genre: 'lignes',
            stockage: 'la table user_achievements',
            domaine: 'La reconnaissance qui ne ment pas',
            table: 'user_achievements',
            modele: 'UserAchievement',
            relations: ['achievements', 'users'],
            ecrivains: [
                'le déblocage' => [
                    'app/Services/AchievementService.php' => ['syncAchievements'],
                ],
            ],
        ),
        'le propriétaire recopié sur les lignes de séance' => projectionsDecrire(
            genre: 'copie',
            stockage: 'workout_lines.user_id',
            domaine: 'La mémoire vérifiable de l’entraînement',
            colonnes: ['user_id'],
            table: 'workout_lines',
            modele: 'WorkoutLine',
            fichierDuModele: 'app/Models/WorkoutLine.php',
            relations: ['workoutLines'],
            ecrivains: [
                'la copie posée à l’enregistrement de la ligne' => [
                    'app/Models/WorkoutLine.php' => ['booted'],
                ],
            ],
        ),
        'la date de séance recopiée sur ses lignes' => projectionsDecrire(
            genre: 'colonne',
            stockage: 'workout_lines.workout_started_at',
            domaine: 'La mémoire vérifiable de l’entraînement',
            colonnes: ['workout_started_at'],
            ecrivains: [
                'la copie posée à l’enregistrement de la ligne' => [
                    'app/Models/WorkoutLine.php' => ['booted'],
                ],
                'la propagation quand la séance change de date' => [
                    'app/Models/Workout.php' => ['booted'],
                ],
            ],
            dette: 'La copie se pose quand la ligne s’écrit et se propage quand la séance change de date : '
                .'deux écrivains, le prix de toute copie dénormalisée tant qu’elle est stockée.',
        ),
        'le propriétaire recopié sur les séries' => projectionsDecrire(
            genre: 'copie',
            stockage: 'sets.user_id',
            domaine: 'La mémoire vérifiable de l’entraînement',
            colonnes: ['user_id'],
            table: 'sets',
            modele: 'Set',
            fichierDuModele: 'app/Models/Set.php',
            relations: ['sets'],
            ecrivains: [
                'la copie posée à l’enregistrement de la série' => [
                    'app/Models/Set.php' => ['booted'],
                ],
                'l’insertion en masse depuis un modèle de séance' => [
                    'app/Actions/CreateWorkoutFromTemplateAction.php' => ['createLinesAndSets'],
                ],
            ],
            dette: 'Un `insert()` en masse ne déclenche pas `saving` : la copie y est posée à la main, '
                .'en double du modèle (.ai/rules/models.md, « Recopier le propriétaire »).',
        ),
        'le propriétaire recopié sur les journaux d’habitude' => projectionsDecrire(
            genre: 'copie',
            stockage: 'habit_logs.user_id',
            domaine: 'La chaîne de jours',
            colonnes: ['user_id'],
            table: 'habit_logs',
            modele: 'HabitLog',
            fichierDuModele: 'app/Models/HabitLog.php',
            ecrivains: [
                'la copie posée à l’enregistrement du journal' => [
                    'app/Models/HabitLog.php' => ['booted'],
                ],
            ],
        ),
        'les doses restantes d’un complément' => projectionsDecrire(
            genre: 'colonne',
            stockage: 'supplements.servings_remaining',
            domaine: 'L’état du jour',
            colonnes: ['servings_remaining'],
            ecrivains: [
                'la saisie par l’utilisateur' => [
                    'app/Http/Requests/SupplementStoreRequest.php' => ['rules'],
                    'app/Http/Requests/SupplementUpdateRequest.php' => ['rules'],
                ],
                'la saisie dans le panneau d’administration' => [
                    'app/Filament/Resources/Supplements/Schemas/SupplementForm.php' => ['configure'],
                ],
                'le décompte à chaque prise' => [
                    'app/Actions/Supplements/ConsumeSupplementAction.php' => ['execute'],
                ],
            ],
            dette: 'Un stock déclaré à la main puis décompté d’une dose à chaque prise : aucun fait ne permet de le '
                .'refaire, puisque le stock de départ n’est pas gardé.',
        ),
        'les valeurs proposées pour une ligne de séance' => projectionsDecrire(
            genre: 'cache',
            stockage: 'le cache, clefs recommended_values:{utilisateur}:v{version}:{exercice}:{séance}',
            domaine: 'La conduite de l’effort',
            marqueur: '/\bcleDeCache\s*\(/',
            ecrivains: [
                'le calcul pour une ligne' => [
                    'app/Services/RecommendedValuesService.php' => ['getRecommendedValues'],
                ],
                'le calcul en lot pour une séance' => [
                    'app/Services/RecommendedValuesService.php' => ['fetchUncachedRecommendedValues'],
                ],
            ],
            dette: 'Deux chemins remplissent la même clef, ligne par ligne et en lot ; ils ont déjà répondu '
                .'différemment sur les mêmes séances (voir `fetchUncachedRecommendedValues()`).',
        ),
    ];
}

/**
 * Les noms d'appel que la garde tient pour des écritures.
 *
 * @return array{argument: list<string>, chaine: list<string>, masse: list<string>, cache: list<string>, champs: list<string>}
 */
function projectionsVerbes(): array
{
    $argument = [
        'update', 'updateQuietly', 'fill', 'forceFill', 'create', 'createQuietly', 'createMany', 'createManyQuietly',
        'forceCreate', 'forceCreateQuietly', 'insert', 'insertOrIgnore', 'insertGetId', 'upsert', 'updateOrCreate',
        'firstOrCreate', 'firstOrNew', 'createOrFirst', 'updateOrInsert', 'increment', 'decrement', 'incrementQuietly',
        'decrementQuietly', 'incrementEach', 'decrementEach', 'setAttribute', 'setRawAttributes', 'attach', 'sync',
        'syncWithoutDetaching', 'syncWithPivotValues', 'updateExistingPivot',
    ];

    return [
        'argument' => $argument,
        'chaine' => [
            ...$argument,
            'delete', 'deleteQuietly', 'forceDelete', 'forceDeleteQuietly', 'destroy', 'insertUsing', 'detach',
            'toggle', 'save', 'saveQuietly', 'saveMany', 'saveManyQuietly', 'push', 'touch', 'truncate', 'restore',
        ],
        'masse' => ['insert', 'insertOrIgnore', 'insertUsing', 'upsert'],
        'cache' => ['put', 'putMany', 'add', 'forever', 'remember', 'rememberForever', 'flexible', 'set', 'setMultiple', 'increment', 'decrement'],
        'champs' => [
            'TextInput', 'Textarea', 'Select', 'DatePicker', 'DateTimePicker', 'TimePicker', 'Toggle', 'Checkbox',
            'CheckboxList', 'Radio', 'Hidden', 'KeyValue', 'TagsInput', 'RichEditor', 'MarkdownEditor', 'ColorPicker',
            'Slider', 'ToggleButtons', 'TextInputColumn', 'SelectColumn', 'ToggleColumn', 'CheckboxColumn',
        ],
    ];
}

/**
 * Les fichiers lus par la garde, relatifs à la racine du dépôt.
 *
 * @return list<string>
 */
function projectionsFichiersLus(): array
{
    $chemins = [];

    foreach (Finder::create()->files()->in([base_path('app'), base_path('routes')])->name('*.php') as $fichier) {
        $chemins[] = str_replace(base_path().'/', '', $fichier->getPathname());
    }

    sort($chemins);

    return $chemins;
}

/**
 * Les jetons qui portent du code : ni espaces ni commentaires.
 *
 * @return list<PhpToken>
 */
function projectionsJetonsDuCode(string $source): array
{
    return array_values(array_filter(
        PhpToken::tokenize($source),
        static fn (PhpToken $jeton): bool => ! $jeton->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML]),
    ));
}

/**
 * La méthode nommée qui contient chaque jeton. Une fermeture compte pour la
 * méthode où elle est écrite : un `static::saving(fn …)` appartient à `booted`.
 *
 * @param  list<PhpToken>  $jetons
 * @return list<string>
 */
function projectionsMethodesDesJetons(array $jetons): array
{
    /** @var list<array{0: string, 1: int}> $pile */
    $pile = [];
    $accolades = 0;
    $enAttente = null;
    $methodes = [];

    foreach ($jetons as $indice => $jeton) {
        if ($jeton->is(T_FUNCTION)) {
            $nom = $jetons[$indice + 1] ?? null;
            $nom = $nom?->text === '&' ? ($jetons[$indice + 2] ?? null) : $nom;

            if ($nom !== null && preg_match('/^\w+$/', $nom->text) === 1) {
                $enAttente = $nom->text;
            }
        } elseif ($jeton->text === ';') {
            $enAttente = null;
        } elseif ($jeton->text === '{' || $jeton->text === '${') {
            if ($enAttente !== null) {
                $pile[] = [$enAttente, $accolades];
                $enAttente = null;
            }

            $accolades++;
        } elseif ($jeton->text === '}') {
            $accolades--;

            if ($pile !== [] && array_last($pile)[1] === $accolades) {
                array_pop($pile);
            }
        }

        $methodes[] = $pile === [] ? '(hors méthode)' : array_last($pile)[0];
    }

    return $methodes;
}

/**
 * Les modèles de l'application, par leur nom court : `new Modele([...])` écrit,
 * `new JsonResponse([...])` non.
 *
 * @return list<string>
 */
function projectionsModeles(): array
{
    $fichiers = glob(app_path('Models/*.php'));

    return $fichiers === false ? [] : array_map(static fn (string $fichier): string => basename($fichier, '.php'), $fichiers);
}

/**
 * La méthode écrit-elle en base par un appel, ou prépare-t-elle les données
 * d'un formulaire Filament ? Une affectation d'indice ne compte que là : un
 * tableau de réponse qui expose la valeur n'est pas une écriture.
 *
 * @param  list<PhpToken>  $jetons
 * @param  list<string>  $methodes
 */
function projectionsLaMethodeEcrit(array $jetons, array $methodes, string $methode): bool
{
    if (str_starts_with($methode, 'mutateFormData')) {
        return true;
    }

    $verbes = [...projectionsVerbes()['argument'], 'save', 'saveQuietly'];

    return array_any(
        $jetons,
        static fn (PhpToken $jeton, int $k): bool => $methodes[$k] === $methode
            && in_array($jeton->text, ['->', '?->', '::'], true)
            && in_array(($jetons[$k + 1] ?? null)?->text, $verbes, true)
            && ($jetons[$k + 2] ?? null)?->text === '(',
    );
}

function projectionsDernierSegment(string $nom): string
{
    $position = strrpos($nom, '\\');

    return $position === false ? $nom : substr($nom, $position + 1);
}

/**
 * Le contenu d'une chaîne littérale simple, ou null pour tout autre jeton.
 */
function projectionsLitteral(?PhpToken $jeton): ?string
{
    if ($jeton === null || ! $jeton->is(T_CONSTANT_ENCAPSED_STRING)) {
        return null;
    }

    return substr($jeton->text, 1, -1);
}

/**
 * L'indice du jeton qui ferme la parenthèse, le crochet ou l'accolade ouvert à `$ouvrant`.
 *
 * @param  list<PhpToken>  $jetons
 */
function projectionsFermant(array $jetons, int $ouvrant): int
{
    $profondeur = 0;
    $total = count($jetons);

    for ($k = $ouvrant; $k < $total; $k++) {
        $texte = $jetons[$k]->text;

        if (in_array($texte, ['(', '[', '{', '${', '#['], true)) {
            $profondeur++;
        } elseif (in_array($texte, [')', ']', '}'], true)) {
            $profondeur--;

            if ($profondeur === 0) {
                return $k;
            }
        }
    }

    return $total - 1;
}

/**
 * L'appel dont un jeton est l'argument, à travers les tableaux littéraux :
 * dans `->update(['colonne' => …])`, « update » ; dans `new Goal([...])`,
 * « new Goal ». Null hors de tout appel : un `return [...]`, une condition,
 * un corps de fermeture.
 *
 * @param  list<PhpToken>  $jetons
 */
function projectionsAppelEnglobant(array $jetons, int $indice): ?string
{
    $profondeur = 0;

    for ($k = $indice - 1; $k >= 0; $k--) {
        $texte = $jetons[$k]->text;

        if (in_array($texte, [')', ']', '}'], true)) {
            $profondeur++;

            continue;
        }

        if (! in_array($texte, ['(', '[', '{', '${', '#['], true)) {
            continue;
        }

        if ($profondeur > 0) {
            $profondeur--;

            continue;
        }

        if ($texte === '[') {
            continue;
        }

        $avant = $jetons[$k - 1] ?? null;

        if ($texte !== '(' || $avant === null) {
            return null;
        }

        if ($avant->is(T_ARRAY)) {
            continue;
        }

        if (! $avant->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
            return null;
        }

        return (($jetons[$k - 2] ?? null)?->is(T_NEW) === true ? 'new ' : '').projectionsDernierSegment($avant->text);
    }

    return null;
}

/**
 * Les méthodes appelées en chaîne à partir d'un `->`, `?->` ou `::`, avec
 * l'étendue de leurs arguments.
 *
 * @param  list<PhpToken>  $jetons
 * @return list<array{nom: string, debut: int, fin: int}>
 */
function projectionsChaineAppelee(array $jetons, int $depart): array
{
    $appels = [];
    $k = $depart;

    while (in_array(($jetons[$k] ?? null)?->text, ['->', '?->', '::'], true)
        && ($jetons[$k + 1] ?? null)?->is(T_STRING) === true
        && ($jetons[$k + 2] ?? null)?->text === '(') {
        $fermant = projectionsFermant($jetons, $k + 2);
        $appels[] = ['nom' => $jetons[$k + 1]->text, 'debut' => $k + 3, 'fin' => $fermant - 1];
        $k = $fermant + 1;
    }

    return $appels;
}

/**
 * La clef `'colonne' =>` apparaît-elle entre deux indices ?
 *
 * @param  list<PhpToken>  $jetons
 */
function projectionsClefEntre(array $jetons, string $colonne, int $debut, int $fin): bool
{
    for ($k = $debut; $k <= $fin; $k++) {
        if (projectionsLitteral($jetons[$k] ?? null) === $colonne && ($jetons[$k + 1] ?? null)?->is(T_DOUBLE_ARROW) === true) {
            return true;
        }
    }

    return false;
}

/**
 * Les écritures des colonnes au nom propre : affectation, appel qui écrit,
 * champ de formulaire, règle de validation, SQL brut.
 *
 * @param  list<PhpToken>  $jetons
 * @param  list<string>  $methodes
 * @return list<array{0: int, 1: string}> L'indice du jeton et la forme reconnue.
 */
function projectionsEcrituresDeColonne(array $jetons, array $methodes, string $chemin, string $colonne): array
{
    $verbes = projectionsVerbes();
    $modeles = projectionsModeles();
    $affectations = ['=', '+=', '-=', '*=', '/=', '.=', '%=', '**=', '??=', '|=', '&=', '^=', '<<=', '>>=', '++', '--'];
    $trouvees = [];

    foreach ($jetons as $i => $jeton) {
        $suivant = $jetons[$i + 1] ?? null;

        if ($jeton->is(T_OBJECT_OPERATOR) && $suivant?->text === $colonne) {
            $operateur = ($jetons[$i + 2] ?? null)?->text;
            $avant = $i - 1;

            while (($jetons[$avant] ?? null)?->is([T_VARIABLE, T_STRING, T_OBJECT_OPERATOR, T_DOUBLE_COLON]) === true) {
                $avant--;
            }

            if (in_array($operateur, $affectations, true) || in_array(($jetons[$avant] ?? null)?->text, ['++', '--'], true)) {
                $trouvees[] = [$i + 1, 'affectation de la propriété'];
            }

            continue;
        }

        if (projectionsLitteral($jeton) !== $colonne) {
            if ($jeton->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])
                && preg_match('/\b(update|insert\s+into|replace\s+into)\b/i', $jeton->text) === 1
                && preg_match('/\b'.preg_quote($colonne, '/').'\b/', $jeton->text) === 1) {
                $trouvees[] = [$i, 'SQL brut'];
            }

            continue;
        }

        $precedent = $jetons[$i - 1] ?? null;

        if ($precedent?->text === '[' && $suivant?->text === ']' && in_array(($jetons[$i + 2] ?? null)?->text, $affectations, true)) {
            if (projectionsLaMethodeEcrit($jetons, $methodes, $methodes[$i])) {
                $trouvees[] = [$i, 'affectation de l’indice'];
            }

            continue;
        }

        $appel = projectionsAppelEnglobant($jetons, $i);

        if ($appel !== null && (in_array($appel, $verbes['argument'], true)
            || (str_starts_with($appel, 'new ') && in_array(substr($appel, 4), $modeles, true)))) {
            $trouvees[] = [$i, "argument de {$appel}()"];

            continue;
        }

        if ($suivant?->is(T_DOUBLE_ARROW) === true
            && ((str_starts_with($chemin, 'app/Http/Requests/') && $methodes[$i] === 'rules')
                || in_array($appel, ['validate', 'validateWithBag'], true))) {
            $trouvees[] = [$i, 'règle de validation'];

            continue;
        }

        $champ = $jetons[$i - 4] ?? null;

        if ($appel === 'make'
            && $champ !== null
            && in_array(projectionsDernierSegment($champ->text), $verbes['champs'], true)
            && $suivant?->text === ')') {
            $chaine = array_column(projectionsChaineAppelee($jetons, $i + 2), null, 'nom');
            $nonSauve = isset($chaine['dehydrated']) && ($jetons[$chaine['dehydrated']['debut']] ?? null)?->is(T_STRING) === true
                && strtolower($jetons[$chaine['dehydrated']['debut']]->text) === 'false';
            $desactive = isset($chaine['disabled']) && ! isset($chaine['dehydrated']);

            if (! $nonSauve && ! $desactive) {
                $trouvees[] = [$i, 'champ de formulaire Filament'];
            }
        }
    }

    return $trouvees;
}

/**
 * Les écritures d'une table : une chaîne qui écrit, partie du modèle, de
 * `new Modele`, de `DB::table('…')` ou d'une relation ; et le SQL brut.
 *
 * Pour une copie au nom commun, la chaîne ne compte que si l'appel qui écrit
 * nomme la colonne en argument — `->where(['user_id' => …])->update([...])` ne
 * l'écrit pas —, ou, pour une insertion en masse, si la méthode la nomme : le
 * tableau inséré se construit en général plus haut.
 *
 * @param  list<PhpToken>  $jetons
 * @param  list<string>  $methodes
 * @param  list<string>  $relations
 * @return list<array{0: int, 1: string}>
 */
function projectionsEcrituresDeTable(array $jetons, array $methodes, string $table, string $modele, array $relations, ?string $colonne): array
{
    $verbes = projectionsVerbes();
    $trouvees = [];
    $motifSql = '/\b(update|insert\s+into|replace\s+into|delete\s+from|truncate(\s+table)?)\s+`?'.preg_quote($table, '/').'`?(\s|$)/i';

    foreach ($jetons as $i => $jeton) {
        $suivant = $jetons[$i + 1] ?? null;
        $chaine = null;
        $origine = '';

        if ($jeton->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && projectionsDernierSegment($jeton->text) === $modele) {
            if (($jetons[$i - 1] ?? null)?->is(T_NEW) === true) {
                $fermant = $suivant?->text === '(' ? projectionsFermant($jetons, $i + 1) : $i;

                if ($colonne === null || projectionsClefEntre($jetons, $colonne, $i + 1, $fermant)) {
                    $trouvees[] = [$i, "new {$modele}"];
                }

                continue;
            }

            if ($suivant?->text === '::') {
                $chaine = projectionsChaineAppelee($jetons, $i + 1);
                $origine = "{$modele}::";
            }
        } elseif ($jeton->is(T_STRING) && $jeton->text === 'table' && in_array(($jetons[$i - 1] ?? null)?->text, ['::', '->'], true)
            && $suivant?->text === '(' && projectionsLitteral($jetons[$i + 2] ?? null) === $table) {
            $chaine = projectionsChaineAppelee($jetons, $i - 1);
            $origine = "table('{$table}')";
        } elseif (in_array($jeton->text, ['->', '?->'], true) && in_array($suivant?->text, $relations, true)
            && ($jetons[$i + 2] ?? null)?->text === '(') {
            $chaine = projectionsChaineAppelee($jetons, $i);
            $origine = '->'.$jetons[$i + 1]->text.'()';
        } elseif ($jeton->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) && preg_match($motifSql, $jeton->text) === 1
            && ($colonne === null || preg_match('/\b'.preg_quote($colonne, '/').'\b/', $jeton->text) === 1)) {
            $trouvees[] = [$i, 'SQL brut'];

            continue;
        }

        if ($chaine === null) {
            continue;
        }

        foreach ($chaine as $appel) {
            if (! in_array($appel['nom'], $verbes['chaine'], true)) {
                continue;
            }

            $nommee = $colonne === null
                || projectionsClefEntre($jetons, $colonne, $appel['debut'], $appel['fin'])
                || (in_array($appel['nom'], $verbes['masse'], true) && projectionsLaMethodeNommeLaClef($jetons, $methodes, $methodes[$i], $colonne));

            if ($nommee) {
                $trouvees[] = [$i, "{$origine}…->{$appel['nom']}()"];
            }

            break;
        }
    }

    return $trouvees;
}

/**
 * @param  list<PhpToken>  $jetons
 * @param  list<string>  $methodes
 */
function projectionsLaMethodeNommeLaClef(array $jetons, array $methodes, string $methode, string $colonne): bool
{
    return array_any(
        $jetons,
        static fn (PhpToken $jeton, int $k): bool => $methodes[$k] === $methode
            && projectionsLitteral($jeton) === $colonne
            && ($jetons[$k + 1] ?? null)?->is(T_DOUBLE_ARROW) === true,
    );
}

/**
 * Le texte de chaque méthode, et la ligne de son premier appel qui écrit le cache.
 *
 * @param  list<PhpToken>  $jetons
 * @param  list<string>  $methodes
 * @return array<string, array{texte: string, indice: int|null}>
 */
function projectionsMethodesEtCache(array $jetons, array $methodes): array
{
    $verbes = projectionsVerbes()['cache'];
    $parMethode = [];

    foreach ($jetons as $i => $jeton) {
        $methode = $methodes[$i];
        $parMethode[$methode] ??= ['texte' => '', 'indice' => null];
        $parMethode[$methode]['texte'] .= $jeton->text.' ';

        $depart = match (true) {
            $jeton->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && projectionsDernierSegment($jeton->text) === 'Cache' => $i + 1,
            $jeton->is(T_STRING) && $jeton->text === 'cache' && ($jetons[$i + 1] ?? null)?->text === '(' => projectionsFermant($jetons, $i + 1) + 1,
            default => null,
        };

        if ($depart === null || $parMethode[$methode]['indice'] !== null) {
            continue;
        }

        foreach (projectionsChaineAppelee($jetons, $depart) as $appel) {
            if (in_array($appel['nom'], $verbes, true)) {
                $parMethode[$methode]['indice'] = $i;

                break;
            }
        }
    }

    return $parMethode;
}

/**
 * Les écritures de chaque projection de l'inventaire dans un source PHP.
 *
 * @param  array<string, array{genre: string, stockage: string, domaine: string, dette: string|null, ecrivains: array<string, array<string, list<string>>>, colonnes: list<string>, table: string, modele: string, fichierDuModele: string, relations: list<string>, marqueur: string}>  $inventaire
 * @return list<array{projection: string, methode: string, ligne: int, forme: string}>
 */
function projectionsEcrituresDuSource(string $source, string $chemin, array $inventaire): array
{
    $jetons = projectionsJetonsDuCode($source);
    $methodes = projectionsMethodesDesJetons($jetons);
    $ecritures = [];

    foreach ($inventaire as $nom => $projection) {
        $trouvees = [];

        if ($projection['genre'] === 'cache') {
            foreach (projectionsMethodesEtCache($jetons, $methodes) as $texte) {
                if ($texte['indice'] !== null && preg_match($projection['marqueur'], $texte['texte']) === 1) {
                    $trouvees[] = [$texte['indice'], 'écriture du cache'];
                }
            }
        } elseif ($projection['genre'] === 'copie') {
            $colonne = $projection['colonnes'][0];

            if ($chemin === $projection['fichierDuModele']) {
                foreach (projectionsEcrituresDeColonne($jetons, $methodes, $chemin, $colonne) as $trouvee) {
                    if ($trouvee[1] === 'affectation de la propriété') {
                        $trouvees[] = $trouvee;
                    }
                }
            }

            array_push($trouvees, ...projectionsEcrituresDeTable($jetons, $methodes, $projection['table'], $projection['modele'], $projection['relations'], $colonne));
        } else {
            foreach ($projection['colonnes'] as $colonne) {
                array_push($trouvees, ...projectionsEcrituresDeColonne($jetons, $methodes, $chemin, $colonne));
            }

            if ($projection['genre'] === 'lignes') {
                array_push($trouvees, ...projectionsEcrituresDeTable($jetons, $methodes, $projection['table'], $projection['modele'], $projection['relations'], null));
            }
        }

        foreach ($trouvees as [$indice, $forme]) {
            $ecritures[] = ['projection' => $nom, 'methode' => $methodes[$indice], 'ligne' => $jetons[$indice]->line, 'forme' => $forme];
        }
    }

    return $ecritures;
}

/**
 * Toutes les écritures relevées dans app/ et routes/, par projection puis par
 * « fichier::méthode ».
 *
 * @return array<string, array<string, list<string>>> Les lignes et formes de chaque écriture.
 */
function projectionsEcrituresDuDepot(): array
{
    $inventaire = projectionsLInventaire();
    $parProjection = array_fill_keys(array_keys($inventaire), []);
    $fichiers = projectionsFichiersLus();

    expect(count($fichiers))->toBeGreaterThan(100, 'presque rien à lire dans app/ : la garde ne prouverait rien');

    foreach ($fichiers as $chemin) {
        foreach (projectionsEcrituresDuSource((string) file_get_contents(base_path($chemin)), $chemin, $inventaire) as $ecriture) {
            $parProjection[$ecriture['projection']]["{$chemin}::{$ecriture['methode']}"][$ecriture['ligne'].$ecriture['forme']] = "ligne {$ecriture['ligne']}, {$ecriture['forme']}";
        }
    }

    return array_map(
        static fn (array $endroits): array => array_map(static function (array $details): array {
            ksort($details, SORT_NATURAL);

            return array_values($details);
        }, $endroits),
        $parProjection,
    );
}

/**
 * Les écrivains connus d'une projection, en « fichier::méthode ».
 *
 * @param  array<string, array<string, list<string>>>  $ecrivains
 * @return array<string, string> Par « fichier::méthode », l'écrivain qui le contient.
 */
function projectionsEcrivainsConnus(array $ecrivains): array
{
    $connus = [];

    foreach ($ecrivains as $ecrivain => $fichiers) {
        foreach ($fichiers as $fichier => $methodes) {
            foreach ($methodes as $methode) {
                $connus["{$fichier}::{$methode}"] = $ecrivain;
            }
        }
    }

    return $connus;
}

it('n’accepte aucun nouvel écrivain d’une valeur dérivée stockée', function (): void {
    $inventaire = projectionsLInventaire();
    $fautes = [];

    foreach (projectionsEcrituresDuDepot() as $nom => $ecritures) {
        $connus = projectionsEcrivainsConnus($inventaire[$nom]['ecrivains']);

        foreach ($ecritures as $endroit => $details) {
            if (isset($connus[$endroit])) {
                continue;
            }

            $fautes[] = sprintf(
                "« %s » (%s) est écrite par %s() — %s —, qui n'est pas l'un de ses écrivains.\n"
                ."    Ses écrivains : %s.\n"
                ."    Passez par l'un d'eux plutôt que d'écrire la valeur vous-même. Si l'endroit n'est qu'une étape d'un\n"
                ."    écrivain existant, ajoutez sa méthode à cet écrivain dans projectionsLInventaire(). Si c'est un nouvel\n"
                .'    écrivain, ajoutez-le avec la raison qui le justifie : il devient une dette nommée.',
                $nom,
                $inventaire[$nom]['stockage'],
                $endroit,
                implode(' ; ', $details),
                implode(', ', array_map(
                    static fn (string $ecrivain, string $endroitConnu): string => "{$endroitConnu}() ({$ecrivain})",
                    $connus,
                    array_keys($connus),
                )),
            );
        }
    }

    expect($fautes)->toBe([], "Une valeur dérivée stockée a un écrivain de plus (#1513, .ai/rules/models.md) :\n\n".implode("\n\n", $fautes));
});

it('ne garde dans la liste que des écrivains qui écrivent encore', function (): void {
    $ecritures = projectionsEcrituresDuDepot();
    $perimes = [];

    foreach (projectionsLInventaire() as $nom => $projection) {
        foreach (projectionsEcrivainsConnus($projection['ecrivains']) as $endroit => $ecrivain) {
            if (! isset($ecritures[$nom][$endroit])) {
                $perimes[] = "{$endroit}() n'écrit plus « {$nom} » ({$ecrivain}) : retirez-le de projectionsLInventaire(), "
                    .'et la dette avec lui s’il ne reste qu’un écrivain.';
            }
        }
    }

    expect($perimes)->toBe([], "La liste des écrivains déborde du code :\n  ".implode("\n  ", $perimes));
});

it('nomme comme dette chaque valeur qui a plus d’un écrivain, et seulement celles-là', function (): void {
    $incoherentes = [];

    foreach (projectionsLInventaire() as $nom => $projection) {
        $plusieurs = count($projection['ecrivains']) > 1;

        if ($plusieurs !== ($projection['dette'] !== null)) {
            $incoherentes[] = $plusieurs
                ? "« {$nom} » a plusieurs écrivains sans dette écrite : dites pourquoi ils sont plusieurs"
                : "« {$nom} » n'a qu'un écrivain et porte pourtant une dette : retirez-la";
        }
    }

    expect($incoherentes)->toBe([], implode("\n", $incoherentes));
});

/**
 * Une écriture de chaque forme que la garde prétend voir, et de chaque
 * projection de l'inventaire : la garde ne devient pas aveugle sans que la
 * suite le dise.
 *
 * @return array<string, array{0: string, 1: string, 2?: string}> Le code, la projection écrite, et le fichier s'il compte.
 */
function projectionsCasDEcriture(): array
{
    return [
        'une affectation' => ['$user->current_streak = 3;', 'la série de jours'],
        'un incrément' => ['$user->longest_streak++;', 'la série de jours'],
        'un incrément préfixé' => ['++$this->user->current_streak;', 'la série de jours'],
        'une affectation composée' => ['$goal->progress_pct += 1;', 'l’avancement d’un objectif'],
        'un indice avant l’enregistrement' => ['$donnees[\'current_value\'] = 0; $goal->fill($donnees)->save();', 'l’avancement d’un objectif'],
        'un update' => ['Workout::query()->whereKey(1)->update([\'workout_volume\' => 0]);', 'le volume d’une séance'],
        'un increment' => ['DB::table(\'workouts\')->increment(\'workout_volume\', 5);', 'le volume d’une séance'],
        'un upsert' => ['Goal::upsert($lignes, [\'id\'], [\'completed_at\']);', 'l’avancement d’un objectif'],
        'un forceFill' => ['$user->forceFill([\'last_workout_at\' => null])->save();', 'la série de jours'],
        'un nouveau modèle' => ['$objectif = new Goal([\'current_value\' => 5]);', 'l’avancement d’un objectif'],
        'un SQL brut' => ['DB::statement(\'update workouts set workout_volume = 0\');', 'le volume d’une séance'],
        'un champ Filament' => ['return [TextInput::make(\'current_value\')->numeric()];', 'l’avancement d’un objectif'],
        'une règle de validation' => ['return [\'servings_remaining\' => [\'integer\']];', 'les doses restantes d’un complément', 'app/Http/Requests/ExempleRequest.php'],
        'un validate' => ['$request->validate([\'current_value\' => [\'numeric\']]);', 'l’avancement d’un objectif'],
        'un nouveau record' => ['$record = new PersonalRecord([\'type\' => \'max_weight\']);', 'les records personnels'],
        'une suppression de records' => ['PersonalRecord::query()->where(\'user_id\', 1)->delete();', 'les records personnels'],
        'un record par la relation' => ['$user->personalRecords()->create([]);', 'les records personnels'],
        'un record par la table' => ['DB::table(\'personal_records\')->insert([]);', 'les records personnels'],
        'un succès par la relation' => ['$user->achievements()->sync([1]);', 'les succès débloqués'],
        'un succès en SQL brut' => ['DB::delete(\'delete from user_achievements where user_id = ?\', [1]);', 'les succès débloqués'],
        'une copie créée par la relation' => ['$seance->workoutLines()->create([\'user_id\' => 1]);', 'le propriétaire recopié sur les lignes de séance'],
        'une date propagée' => ['$seance->workoutLines()->update([\'workout_started_at\' => $date]);', 'la date de séance recopiée sur ses lignes'],
        'une copie en masse' => ['$lignes[] = [\'user_id\' => 1]; \App\Models\Set::insert($lignes);', 'le propriétaire recopié sur les séries'],
        'une copie dans son modèle' => ['$set->user_id = 1;', 'le propriétaire recopié sur les séries', 'app/Models/Set.php'],
        'une copie par la table' => ['DB::table(\'habit_logs\')->insert([\'user_id\' => 1]);', 'le propriétaire recopié sur les journaux d’habitude'],
        'une valeur en cache' => ['Cache::put(self::cleDeCache(1, 2, 3), [], 300);', 'les valeurs proposées pour une ligne de séance'],
    ];
}

it('reconnaît une écriture sous la forme qu’elle prétend voir', function (string $code, string $projection, string $chemin = 'app/Exemple.php'): void {
    $ecritures = projectionsEcrituresDuSource("<?php\nclass Exemple\n{\n    public function rules(): void\n    {\n        {$code}\n    }\n}\n", $chemin, projectionsLInventaire());

    expect(array_column($ecritures, 'projection'))->toContain($projection);
})->with(projectionsCasDEcriture());

it('a un cas d’écriture pour chaque projection de l’inventaire', function (): void {
    $couvertes = array_unique(array_column(projectionsCasDEcriture(), 1));

    expect(array_values(array_diff(array_keys(projectionsLInventaire()), $couvertes)))
        ->toBe([], 'ajoutez à projectionsCasDEcriture() une écriture de ces projections, pour que la garde ne puisse pas devenir aveugle à leur sujet');
});

it('ne prend ni une lecture ni l’écriture d’autre chose pour une écriture de projection', function (string $code, string $chemin = 'app/Exemple.php'): void {
    $ecritures = projectionsEcrituresDuSource("<?php\nclass Exemple\n{\n    public function lire(): mixed\n    {\n        {$code}\n    }\n}\n", $chemin, projectionsLInventaire());

    expect($ecritures)->toBe([]);
})->with([
    'une réponse qui expose la valeur' => ['return [\'current_streak\' => $this->series->currentStreakFor($user)];'],
    'une réponse complétée par indice' => ['$reponse[\'current_streak\'] = $this->series->currentStreakFor($user); return $reponse;'],
    'une réponse construite par un objet' => ['return new JsonResponse([\'current_value\' => $goal->current_value]);'],
    'une comparaison' => ['return $goal->current_value === $goal->target_value;'],
    'une lecture de propriété' => ['$ancien = $workout->workout_volume;'],
    'un filtre dans une écriture d’autre chose' => ['$requete->whereNull(\'completed_at\')->update([\'title\' => \'x\']);'],
    'une colonne de tableau Filament' => ['return [TextColumn::make(\'current_value\')->sortable()];'],
    'un champ Filament qui ne sauve pas' => ['return [TextInput::make(\'current_streak\')->readOnly()->dehydrated(false)];'],
    'un champ Filament désactivé' => ['return [TextInput::make(\'progress_pct\')->disabled()];'],
    'une règle hors d’une requête' => ['return [\'servings_remaining\' => [\'integer\']];'],
    'une lecture de records' => ['return PersonalRecord::query()->where(\'user_id\', 1)->get();'],
    'un maximum lu dans la table' => ['return DB::table(\'personal_records\')->where(\'type\', \'max_weight\')->max(\'value\');'],
    'une lecture par la relation' => ['return $user->achievements()->get();'],
    'un SQL de lecture' => ['return DB::select(\'select workout_volume from workouts\');'],
    'une ligne créée sans la copie' => ['$seance->workoutLines()->create([\'exercise_id\' => 1]);'],
    'un propriétaire filtré' => ['return WorkoutLine::query()->where(\'user_id\', 1)->get();'],
    'un propriétaire filtré dans une écriture' => ['WorkoutLine::query()->where([\'user_id\' => 1])->update([\'notes\' => null]);'],
    'une écriture commentée' => ["// \$user->current_streak = 0;\n        return null;"],
    'une lecture du cache' => ['return Cache::get(self::cleDeCache(1, 2, 3));'],
    'le propriétaire d’une autre table' => ['$exercice->user_id = 5;'],
]);

it('ne laisse qu’un reconstructeur à chaque statistique en cache', function (): void {
    $parFamille = [];

    foreach (projectionsFichiersLus() as $chemin) {
        $jetons = projectionsJetonsDuCode((string) file_get_contents(base_path($chemin)));

        foreach (projectionsMethodesEtCache($jetons, projectionsMethodesDesJetons($jetons)) as $methode => $texte) {
            preg_match_all(
                '/Cache\s*::\s*(?:remember|rememberForever|flexible|put|add|forever)\s*\(\s*\S*ClesDeStats\s*::\s*(?:seances|mesures)\s*\(\s*\$\w+\s*,\s*["\']\s*([a-z0-9_]+)/',
                $texte['texte'],
                $familles,
            );

            foreach (array_unique($familles[1]) as $famille) {
                $parFamille[$famille][] = "{$chemin}::{$methode}()";
            }
        }
    }

    expect(count($parFamille))->toBeGreaterThan(10, 'aucune statistique en cache trouvée : la garde ne prouverait rien');

    $partagees = array_filter($parFamille, static fn (array $ecrivains): bool => count($ecrivains) > 1);

    expect($partagees)->toBe([], 'une clef de statistique est écrite par plusieurs méthodes : un seul `Cache::remember()` par clef, '
        .'les autres la lisent ou l’invalident par ClesDeStats (#1513).');
});
