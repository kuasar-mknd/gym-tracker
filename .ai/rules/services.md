---
paths:
  - 'app/Services/**'
---

# Services

## Mesurer un coût SQL : trois pièges qui rendent la mesure fausse
Seuls les deltas de `show session status like 'Handler_read%'` disent la vérité. `EXPLAIN.rows` est une estimation ; sommer un arbre `EXPLAIN ANALYZE` compte deux fois les nœuds imbriqués ; compter les requêtes ne mesure rien.

Trois pièges de protocole, rencontrés en série sur #1593 :

1. **Fenêtre glissante** — semer une séance par jour et faire varier la profondeur ne mesure rien : une fenêtre de 90 jours contient légitimement plus de lignes sur un compte plus dense. Fixer le contenu de la fenêtre, ne faire varier que l'historique ANCIEN.
2. **Table minuscule** — MySQL balaie au lieu de parcourir l'index en dessous de quelques centaines de lignes. Comparer petit et grand mesure ce basculement, pas le défaut. Placer la mesure entre deux états déjà volumineux.
3. **Témoin instable** — un test qui assère un nombre de lectures passe seul et tombe dans la suite : les statistiques que MySQL tient par table bougent avec les tests voisins. Un témoin de coût doit porter sur la FORME de la requête, pas sur le compte.

Voir `.ai/rules/actions.md` pour le saut d'index par `min()`.

## Une relation qui lit une ligne exige une contrainte d'unicité
`keyBy()` et `firstOrNew()` ne gardent que la dernière ligne d'une clef répétée. La ligne en trop n'est alors ni corrigée, ni supprimée, indéfiniment — elle annonce une valeur que plus rien ne soutient.

Deux occurrences réelles, même forme : les préférences d'échauffement (relation `hasOne` sans unicité), puis `personal_records` où le couple (user, exercise, type) portait deux lignes et où `recompute()` en ignorait une. Aucune des deux ne rendait d'erreur : le défaut est silencieux par construction.

`$x ??= new Model(...)` sur un couple non unique est une course : deux écritures concurrentes créent deux lignes.

Donc, dès qu'un code lit UNE ligne pour un couple de colonnes : poser l'index unique en base, et faire écarter les doublons par le code de recalcul lui-même — il doit pouvoir réparer une base d'avant la contrainte, et ne dépendre d'aucune garantie qu'il ne vérifie pas.
