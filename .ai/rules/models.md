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
