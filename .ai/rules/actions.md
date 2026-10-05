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

`ResolveSocialUserAction` n'ouvre un compte existant que si l'adresse rendue est la sienne : identique octet par octet, ou en ASCII imprimable et égale en minuscules ASCII (`strtolower()`, jamais `mb_strtolower()`, qui crée lui-même des égalités). L'identité (`provider`, `provider_id`, filtrée exactement en PHP) ne suffit pas : l'adresse du compte a pu changer depuis la liaison (profil, panneau), et l'ancienne recherche par adresse a pu poser des liaisons sur des adresses seulement proches. Aucune colonne ne garde l'adresse de la liaison, donc rien ne distingue ces liaisons d'une liaison saine : une identité qui rend une autre adresse que celle de son compte est refusée et journalisée sans l'adresse, et ne crée pas de compte. Reconnaître une identité dont l'adresse a changé chez le fournisseur demanderait cette colonne (adresse ou date de la liaison) ; sans elle, ne pas le rétablir. Le rattachement par l'adresse exige en plus l'ASCII imprimable, une adresse garantie par le fournisseur, un compte vérifié et non lié à une autre identité du même fournisseur. Une adresse refusée ne crée pas de compte non plus : l'index unique, de la même collation, tiendrait les deux pour une seule. Un message de refus ne propose l'inscription que si aucun compte n'occupe l'adresse, et renvoie sinon au mot de passe (« Mot de passe oublié ? »). Témoin : `ConnexionSocialeAdresseExacteTest`, qui passe par la vraie route de rappel pour Google, GitHub et Apple, et rejoue un changement d'adresse suivi d'une réinitialisation.
