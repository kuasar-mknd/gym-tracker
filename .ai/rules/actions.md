---
paths:
  - 'app/Actions/**'
---

# Actions

## Sauter une colonne : min() sur la plage, jamais order by limit 1
Pour trouver la valeur suivante d'une colonne indexée (`where user_id = ? and part > ?`), écrire `select min(part) ...` et non `select part ... order by part limit 1`.

Les deux paraissent équivalents, mais seul `min()` forme une plage sur l'index : 1 lecture. L'`order by ... limit 1` produit un `Covering index lookup` sur la seule égalité, puis un `Filter` ligne à ligne — mesuré 1 201 lectures là où `min()` en coûtait 1. Une CTE récursive bâtie sur le même `min()` est pire encore (le sous-plan n'est pas optimisé dans le membre récursif).

Mesurer avec les deltas de `show session status like 'Handler_read%'`. `EXPLAIN.rows` est une estimation, et sommer un arbre `EXPLAIN ANALYZE` compte deux fois les nœuds imbriqués.

Voir `FetchBodyPartMeasurementsIndexAction` et son témoin `MesuresIndexConstantTest`.
