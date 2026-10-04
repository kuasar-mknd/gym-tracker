---
paths:
  - 'app/Models/**'
---

# Models

## Recopier le propriétaire : dériver de la clef, et penser aux inserts en masse
Quand une requête filtre sur une table et ordonne sur une autre, aucun index ne sert les deux : MySQL matérialise la jointure et la trie. Le remède est une copie du propriétaire sur la table filtrée — `workout_lines.user_id` (#1601), `habit_logs.user_id` (#1604), `sets.user_id` (#1620).

Deux pièges, rencontrés chacun deux fois :

1. **Dériver de la CLEF, pas de la relation.** `$modele->relation` rend l'instance mise en cache, donc l'ancien parent quand c'est justement la clef étrangère qui vient de changer. Écrire `Parent::whereKey($modele->parent_id)->value('user_id')`, et ne recalculer que si `isDirty('parent_id')`.
2. **Les `insert()` en masse ne déclenchent aucun événement.** Le hook `saving` ne les voit pas : la colonne doit être posée dans le tableau inséré. Chercher `::insert(` avant de se fier à la copie.

Pas de clef étrangère sur la copie : la copie n'est pas le lien, la clef d'origine l'est déjà et porte la cascade.

## Le journal d'activité ne suit que les comptes, jamais les modèles métier
Depuis #1670 (2026-09-03), seuls `User` et `Admin` portent `LogsActivity` : le journal d'activité est un audit des comptes (identité, actions d'administration), pas un historique des données métier, qui sont déjà en base. Chaque écriture y coûtait une instruction SQL de plus en production (0,35 à 1,7 s avant réglage) et personne ne le lisait. Ne pas rajouter le trait à un modèle métier ; le journal se lit dans le panneau (« Journal d'audit », `ActivityLogResource`, lecture seule derrière la permission Shield `ViewAny:ActivityLog`) et se purge chaque nuit à 180 jours (`activitylog:clean`, `routes/console.php`). `ActivityLogResourceTest` garde que les six modèles métier n'écrivent plus. Corollaire : `tests/Feature/Perf/EcrituresParOperationTest.php` fige le nombre d'écritures de chaque opération de la page de séance ; une écriture de plus se décide et se justifie dans ce test, elle ne s'ajoute pas par mégarde.

## Un total par utilisateur se lit, il ne se stocke pas

`users.total_volume` était tenu à chaque série par un `increment()` sous verrou, recalé en fin de séance et surveillé chaque nuit : trois mécanismes pour une colonne qu'un seul service lisait. En production, cette écriture coûtait jusqu'à une seconde par série. Le total se lit désormais par `User::volumeSouleve()`, une somme sur `workouts.workout_volume` (1 à 2 ms). Avant d'ajouter un compteur dénormalisé sur `users`, compter ses lecteurs : s'il n'y en a qu'un, une somme au moment de la lecture coûte moins que l'entretien à chaque écriture. `VolumeDeriveTest` tient le contrat : valider une série n'écrit rien dans `users`.

## Une valeur dérivée stockée garde ses écrivains : la liste vit dans un test
Une projection est une valeur qui se déduit d'autres données : volume de séance, série de jours, avancement d'objectif, records, succès, propriétaire recopié sur les lignes, les séries et les journaux d'habitude, date recopiée sur les lignes de séance, doses restantes, valeurs proposées en cache. Celles qui sont stockées ne s'écrivent qu'aux endroits listés dans `projectionsLInventaire()`, en tête de `tests/Feature/Conventions/LesProjectionsGardentLeursEcrivainsTest.php` : fichier, méthode, et ce que fait chaque écrivain.

Pourquoi : le modèle cible de la refonte (#1513) ne garde qu'un reconstructeur par projection, et chaque écrivain de plus est une façon de plus de diverger. En attendant la refonte, le propriétaire du dépôt a choisi de figer les écrivains actuels plutôt que de trancher maintenant ; une valeur qui en a plusieurs porte sa dette par écrit dans la liste (série de jours, volume de séance, avancement d'objectif, records, date recopiée sur les lignes de séance, propriétaire recopié sur les séries, doses restantes, valeurs proposées). Le test échoue quand un nouvel endroit écrit l'une de ces valeurs, et quand un écrivain listé ne l'écrit plus.

Avant d'écrire une de ces valeurs, appeler l'écrivain existant (`StreakService`, `Workout::recalculerLeVolume()`, `GoalService::syncGoals()`, `PersonalRecordService::recompute()`…). Un nouvel écrivain se décide : il entre dans la liste avec sa raison, il ne s'y glisse pas pour faire passer la suite. Une nouvelle valeur dérivée stockée entre dans l'inventaire avec un cas dans `projectionsCasDEcriture()`. La garde lit le texte : elle ne voit ni un nom de colonne calculé, ni ce que la base écrit seule (`ON DELETE SET NULL`), ni le code hors de app/ et de routes/ ; son docbloc tient la liste de ce qui lui échappe.

## Un modèle qui étend celui d'un paquet fixe sa table et entre dans la liste des classes sérialisables
La sous-classe déduit son nom de table du sien (`TachePlanifiee` cherchait `tache_planifiees`) : poser `protected $table` avec `#[\Override]`. Tout modèle de `app/Models` doit figurer dans `config/cache.php` (`serializable_classes`), la garde `SerializableClassesTest` le tient. Les statuts de spatie/laravel-health sont des spatie/enum (`Status::ok()`) : Rector les réécrit à tort en constante native (`Status::OK`, inexistante) ; comparer `->status->value` à `'ok'`. Un modèle qui masque des secrets avant l'écriture (`ExceptionEnregistree`) type ses réaffectations par `@var` sur la variable, car les propriétés du paquet sont annotées plus étroitement que `array<mixed>`.

## Ce qui désigne un compte par une relation polymorphe s'efface avec lui, dans `User::delete()`
Une relation polymorphe (`notifiable_*`, `subscribable_*`, `tokenable_*`, `model_*`, `causer_*`, `subject_*`) n'a pas de clé étrangère : `ON DELETE CASCADE` ne l'atteint pas, et supprimer un compte laissait ses notifications, ses abonnements push, ses jetons, ses rôles et son historique en base (#1935). Le trait `EffaceSesTracesPolymorphes` remplace `User::delete()` : il efface ces lignes dans la même transaction que le compte, en filtrant toujours sur le type ET l'identifiant, parce qu'un administrateur porte souvent l'identifiant d'un compte et que ses rôles et son audit vivent dans les mêmes tables.

Ne pas déplacer cet effacement dans un écouteur `deleting` ou `deleted` : il ne pourrait pas ouvrir la transaction, et `deleteQuietly()` le sauterait. Ne jamais supprimer de comptes par le constructeur de requêtes (`User::query()->delete()`), qui contourne `delete()`. Une nouvelle colonne `*_type` entre dans `User::TRACES_POLYMORPHES` (et se sème dans `SuppressionDuCompteTest`) ou dans les exceptions justifiées de `LesTablesPolymorphesSuiventLeCompteTest`, qui lit la base de test et non le seul dump : une table arrive par une migration bien avant d'entrer dans le dump.

## Le propriétaire d'une séance est fixé à sa création
`workout_lines.user_id` et `sets.user_id` recopient `workouts.user_id`. `WorkoutLine::booted` recopie le propriétaire à chaque enregistrement de la ligne, `Set::booted` seulement quand `workout_line_id` change (ou que la copie est nulle), et l'insertion en masse des séries dans `CreateWorkoutFromTemplateAction::createLinesAndSets()` le pose à la main. Aucun ne propage un changement de la séance : sans cet invariant, il faudrait dans `projectionsLInventaire()` un écrivain de propagation de plus, comme celui de `workout_started_at` dans `Workout::booted()`. Le back-office laissait pourtant changer le propriétaire d'une séance, et lignes, séries, série de jours et records restaient à l'ancien compte (#1933). Depuis, le panneau n'offre plus le champ qu'à la création : il est désactivé dans `WorkoutForm`, que partagent la page de modification et l'action de modification de la table. Et `Workout::booted()` lève une `LogicException` quand un enregistrement change le `user_id` d'une séance existante ; seuls les chemins qui sautent les événements lui échappent (`saveQuietly()`, `withoutEvents()`, le constructeur de requêtes).

Un transfert de séance, s'il devient un jour nécessaire, sera une action dédiée qui, en transaction, réécrit les copies, recalcule série de jours et records des deux comptes et oublie leurs caches ; ses écrivains entreront dans l'inventaire. Dans un test, le propriétaire se pose à la création (`Workout::factory()->create(['user_id' => …])`), jamais par `forceFill()` après coup.
