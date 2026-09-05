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
Depuis #1670 (2026-09-03), seuls `User` et `Admin` portent `LogsActivity` : le journal d'activité est un audit des comptes (identité, actions d'administration), pas un historique des données métier, qui sont déjà en base. Chaque écriture y coûtait une instruction SQL de plus sur le NAS (0,35 à 1,7 s avant réglage) et personne ne le lisait. Ne pas rajouter le trait à un modèle métier ; le journal se lit dans le panneau (« Journal d'audit », `ActivityLogResource`, lecture seule derrière la permission Shield `ViewAny:ActivityLog`) et se purge chaque nuit à 180 jours (`activitylog:clean`, `routes/console.php`). `ActivityLogResourceTest` garde que les six modèles métier n'écrivent plus. Corollaire : `tests/Feature/Perf/EcrituresParOperationTest.php` fige le nombre d'écritures de chaque opération de la page de séance ; une écriture de plus se décide et se justifie dans ce test, elle ne s'ajoute pas par mégarde.

## Un total par utilisateur se lit, il ne se stocke pas

`users.total_volume` était tenu à chaque série par un `increment()` sous verrou, recalé en fin de séance et surveillé chaque nuit : trois mécanismes pour une colonne qu'un seul service lisait. Sur le NAS de production, cette écriture coûtait jusqu'à une seconde par série. Le total se lit désormais par `User::volumeSouleve()`, une somme sur `workouts.workout_volume` (1 à 2 ms). Avant d'ajouter un compteur dénormalisé sur `users`, compter ses lecteurs : s'il n'y en a qu'un, une somme au moment de la lecture coûte moins que l'entretien à chaque écriture. `VolumeDeriveTest` tient le contrat : valider une série n'écrit rien dans `users`.
