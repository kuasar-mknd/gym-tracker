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

## Un service qui écrit une valeur dérivée stockée figure dans la liste de ses écrivains
Les écrivains des valeurs dérivées stockées (série de jours, avancement d'objectif, records, succès, valeurs proposées en cache…) sont figés dans `tests/Feature/Conventions/LesProjectionsGardentLeursEcrivainsTest.php`, avec leur dette quand ils sont plusieurs (#1513). Un service qui se met à écrire l'une d'elles fait tomber ce test : passer par l'écrivain existant, ou l'ajouter à la liste en disant pourquoi. Chaque clef de statistique en cache n'a qu'un `Cache::remember()` ; le même test le vérifie. La règle complète est dans `.ai/rules/models.md`, « Une valeur dérivée stockée garde ses écrivains ».

## Les records s'écrivent sous le verrou de leur exercice, qui n'est pas réentrant
Deux synchronisations de file et la reconstruction lancée par la requête web écrivaient les records du même exercice en même temps : premier record inséré deux fois, ou record écrasé par la plus faible des deux séries (#1984). `PersonalRecordService` fait toute lecture-comparaison-écriture sous `Cache::lock("records:{compte}:{exercice}")` (`sousLeVerrou()`), autour de `processUpdates()` et de `recompute()`. Une transaction avec `lockForUpdate()` ne suffit pas : avant le premier record, il n'y a aucune ligne à verrouiller.

Les lectures qui DÉCIDENT d'une écriture se font sous le même verrou, pas seulement celle des records : la synchronisation relit la série (`fresh()`, puis `shouldSkipSync()`), parce que le travail de file l'a chargée à son démarrage et qu'une correction faite depuis n'y paraissait pas ; `refreshRecordsHeldBy()` demande ce que la série détient dans la section qui reconstruit, parce que, posée avant, la question croisait la synchronisation de la série elle-même et répondait « rien » juste avant qu'elle écrive l'ancienne valeur. `Set::deleting` retient ce que la série détient hors du verrou (la base l'oublie à la suppression) ; `refreshFor()` y ajoute, sous le verrou, les records de l'exercice restés sans série, qu'une synchronisation a pu écrire entre les deux.

Le verrou n'est pas réentrant : une section qui rappelle `recompute()` du même exercice attendrait sa propre fin jusqu'au délai. Le corps de `recompute()` vit donc dans `reconstruire()`, sans verrou, que les sections qui tiennent déjà le verrou appellent ; le repli sur `UniqueConstraintViolationException` appelle `recompute()` APRÈS avoir rendu le verrou, et les annonces de nouveau record partent hors verrou. Le verrou se rend en fin de section, pas à la validation d'une transaction : n'appeler le service dans une transaction ouverte qu'en le sachant. Pour rejouer un entrelacement dans un test, `tests/Feature/Services/RecordsConcurrentsTest.php` arrête la première écriture dans une fibre (`DB::listen`) et fait rendre la main à chaque attente du verrou (`Sleep::fake()`, `Sleep::whenFakingSleep()`).

## Le diagnostic ne rend que de la structure, et le texte libre seulement par `TexteNonFiable`
Réglé le 2026-10-12 (#2032). Une projection de `app/Services/Diagnostic/Projections` nomme ses colonnes (jamais `select *`, `toArray()` ni un modèle sérialisé), lit trente jours au plus et au plus `LecturesBornees::LIGNES_AU_PLUS` lignes, une de plus pour savoir si elle a débordé, et fait passer chaque valeur par sa forme : `Vocabulaire::forme()`, `parmi()` ou `fichier()`, `ValeurLue` pour les nombres et les dates. Ce qui n'a pas sa forme devient `null`, jamais un texte approché. Un motif de forme s'écrit `^…$` avec le modificateur `D` : sans lui, `$` admet un saut de ligne final, et `"workouts.show\n"` passait pour un nom de route. Le texte libre (message, chemin, page, pile) ne sort que dans un `TexteNonFiable`, sous la clé `non_fiable` d'un détail, et seulement sous `TexteBrut` : `EnveloppeDeDiagnostic` l'expurge, et lève s'il est ailleurs. Une clé nouvelle entre avec sa forme dans `vocabulaireFermeFormes()` de `VocabulaireFermeDuDiagnosticTest` ; une source nouvelle sème ses canaris par un vrai chemin dans `LesCanarisNeSortentPasDuDiagnosticTest` ; une lecture nouvelle tient le test de forme des requêtes (`EnveloppeEtResumeDuDiagnosticTest`), parce qu'une lecture sans borne ne se voit pas dans le résumé, qui plafonne ce qu'il compte. L'étape des erreurs SQL reprend la règle qui tait les valeurs à la source (`TexteDuServeurSansValeurs::de()`), sans la recopier, et ferme avant elle une citation qu'un texte coupé a laissée ouverte. Une étape de l'expurgation passe par `Motifs`, qui vérifie chaque appel (un échec rend `[illisible]`, jamais le texte d'origine), avec des répétitions bornées, et `ExpurgeLeTexteTest` lui oppose une chaîne de 8 Kio en moins de 50 ms. Rector propose d'écrire `array_reduce($passes, rawurldecode(...), $texte)` : une fonction interne refuse l'argument de trop qu'`array_reduce` lui passe (`ArgumentCountError`), ne pas accepter cette réécriture.
