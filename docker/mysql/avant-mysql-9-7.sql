-- Ce qu'un serveur MySQL 9.7 ne reprendra pas d'un volume 8.4 : un appel de
-- md5(), de sha1() ou de son alias sha(), qui ne sont plus des fonctions
-- natives sous 9.7 (.ai/rules/dependencies.md, « MySQL 9.7 : la CI
-- d'abord… »). Se lance en root sous 8.4, avant de basculer, dans chaque base
-- du serveur : une ligne par objet qui les appelle, rien quand le volume peut
-- passer. 8.4 réécrit SHA1() en sha() et les noms en minuscules ; SHA2() n'est
-- pas visée.
--
-- Mesuré le 2026-10-17, chaque forme seule sur un volume 8.4.11 mis à niveau
-- par 9.7.2 : une colonne générée virtuelle indexée, un défaut calculé, un
-- index fonctionnel ou une contrainte CHECK qui les appellent arrêtent le
-- serveur dans la mise à niveau du dictionnaire de données (« Incorrect
-- information in file ») ; une colonne générée stockée la passe, mais sa table
-- ne se lit plus ; une vue et un déclencheur la passent aussi, puis tombent à
-- leur premier usage, comme une routine ou un événement.
SET @appel = '(^|[^a-z0-9_])(md5|sha1?)[[:space:]]*[(]';

SELECT objet, base, nom, expression FROM (
    SELECT 'colonne générée' AS objet, TABLE_SCHEMA AS base, CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS nom, GENERATION_EXPRESSION AS expression
        FROM information_schema.COLUMNS
        WHERE REGEXP_LIKE(GENERATION_EXPRESSION, @appel, 'i')
    UNION ALL
    SELECT 'défaut calculé', TABLE_SCHEMA, CONCAT(TABLE_NAME, '.', COLUMN_NAME), COLUMN_DEFAULT
        FROM information_schema.COLUMNS
        WHERE EXTRA LIKE '%DEFAULT_GENERATED%' AND REGEXP_LIKE(COLUMN_DEFAULT, @appel, 'i')
    UNION ALL
    SELECT 'index fonctionnel', TABLE_SCHEMA, CONCAT(TABLE_NAME, '.', INDEX_NAME), EXPRESSION
        FROM information_schema.STATISTICS
        WHERE REGEXP_LIKE(EXPRESSION, @appel, 'i')
    UNION ALL
    SELECT 'contrainte CHECK', contraintes.TABLE_SCHEMA, CONCAT(contraintes.TABLE_NAME, '.', controles.CONSTRAINT_NAME), controles.CHECK_CLAUSE
        FROM information_schema.CHECK_CONSTRAINTS AS controles
        JOIN information_schema.TABLE_CONSTRAINTS AS contraintes
            ON contraintes.CONSTRAINT_SCHEMA = controles.CONSTRAINT_SCHEMA AND contraintes.CONSTRAINT_NAME = controles.CONSTRAINT_NAME
        WHERE REGEXP_LIKE(controles.CHECK_CLAUSE, @appel, 'i')
    UNION ALL
    SELECT 'vue', TABLE_SCHEMA, TABLE_NAME, VIEW_DEFINITION
        FROM information_schema.VIEWS
        WHERE REGEXP_LIKE(VIEW_DEFINITION, @appel, 'i')
    UNION ALL
    SELECT 'déclencheur', TRIGGER_SCHEMA, CONCAT(EVENT_OBJECT_TABLE, '.', TRIGGER_NAME), ACTION_STATEMENT
        FROM information_schema.TRIGGERS
        WHERE REGEXP_LIKE(ACTION_STATEMENT, @appel, 'i')
    UNION ALL
    SELECT 'routine', ROUTINE_SCHEMA, ROUTINE_NAME, ROUTINE_DEFINITION
        FROM information_schema.ROUTINES
        WHERE REGEXP_LIKE(ROUTINE_DEFINITION, @appel, 'i')
    UNION ALL
    SELECT 'événement', EVENT_SCHEMA, EVENT_NAME, EVENT_DEFINITION
        FROM information_schema.EVENTS
        WHERE REGEXP_LIKE(EVENT_DEFINITION, @appel, 'i')
) AS appels
WHERE base NOT IN ('mysql', 'sys', 'information_schema', 'performance_schema')
ORDER BY base, objet, nom;
