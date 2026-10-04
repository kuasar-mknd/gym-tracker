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

## Le saut lui-même tourne dans la base, en une instruction

Une boucle PHP qui répète `min(col) where col > curseur` coûte un aller-retour par valeur trouvée : quatre-vingt-un pour quatre-vingts exercices sur la page des séances, à chaque expiration du cache. La même marche s'écrit en une expression de table récursive (`with recursive saut as (select min(...) ... union all select (select min(...) where col > saut.col) from saut where saut.col is not null)`), avec exactement les mêmes lectures d'index et un seul aller-retour. `DB::scalar()` rend le compte ; le résultat est `mixed`, donc `is_numeric()` avant le `(int)`. Limite à connaître : MySQL borne la récursion à mille pas (`cte_max_recursion_depth`), largement au-dessus d'une bibliothèque d'exercices. Témoin : `WorkoutsIndexBudgetDeRequetesTest`, qui tient la page sous quinze requêtes à froid.

## Une égalité d'identité ne se confie pas à la collation
Les colonnes texte de `users` (`email`, `provider`, `provider_id`) sont en `utf8mb4_unicode_ci` : `where('email', $adresse)` rend aussi un compte dont l'adresse n'a qu'un accent, une casse Unicode, un « ß » pour « ss », un signe kelvin, une pleine chasse ou une espace finale de différence. La base sert à trouver les candidats, l'égalité se décide en PHP, octet par octet. La connexion sociale ouvrait ainsi le compte d'un autre sur une adresse seulement proche, vérifiée chez le fournisseur.

`ResolveSocialUserAction` cherche d'abord l'identité (`provider`, `provider_id`, filtrée exactement en PHP) ; une identité trouvée n'ouvre pas son compte quand l'adresse rendue n'est que proche de celle du compte (confondue par la base, différente en minuscules ASCII) : c'est la trace d'une liaison posée par l'ancienne recherche par adresse, refusée et journalisée sans l'adresse. Le résolveur n'admet ensuite une adresse que si elle est en ASCII imprimable et identique à celle du compte en minuscules ASCII (`strtolower()`), jamais par `mb_strtolower()`, qui crée lui-même des égalités. Une adresse refusée ne crée pas de compte non plus : l'index unique, de la même collation, tiendrait les deux pour une seule. Témoin : `ConnexionSocialeAdresseExacteTest`, qui passe par la vraie route de rappel pour Google, GitHub et Apple.
