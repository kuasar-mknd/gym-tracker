-- Ce qu'un serveur MySQL 9.7 ne reprendra pas d'un volume 8.4 : un appel de
-- md5(), de sha1() ou de son alias sha(), qui ne sont plus des fonctions
-- natives sous 9.7 (.ai/rules/dependencies.md, « MySQL 9.7 : la CI
-- d'abord… »). Puis ce qu'un essai sous 9.7 a pu écrire : des lignes
-- orphelines. Se lance en root sous 8.4, avant de basculer, dans chaque base
-- du serveur : une ligne par objet qui appelle ces fonctions, une ligne par clé
-- étrangère qui a des orphelines, rien quand le volume peut passer. Pour ne
-- lire qu'une base, poser `SET @seule_base = '<base>';` avant, dans la même
-- session. 8.4 réécrit SHA1() en sha() et les noms en minuscules ; SHA2()
-- n'est pas visée.
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
    AND (@seule_base IS NULL OR base = @seule_base)
ORDER BY base, objet, nom;

-- Les lignes orphelines : sous chaque clé étrangère, celles dont le parent
-- n'existe pas, comptées. MySQL 9.7.2 ne vérifie plus une clé quand l'index
-- qui la porte a une colonne nulle dans la ligne écrite (#2095,
-- .ai/rules/database.md) : une base qui a tourné sous 9.7 avant que ses index
-- soient rangés (Sail, un essai, une archive restaurée) a pu en recevoir, et
-- aucun serveur ne revérifie une ligne déjà écrite. Une ligne dont une colonne
-- de la clé est nulle n'est pas orpheline : la clé ne la vise pas. Une requête
-- par clé, réunies et lancées d'un coup.
SET SESSION group_concat_max_len = 16777216;

SET @orphelines = (
    SELECT CONCAT(
        'SELECT base, nom, lignes FROM (',
        COALESCE(GROUP_CONCAT(compte ORDER BY base, nom SEPARATOR ' UNION ALL '), 'SELECT NULL AS base, NULL AS nom, 0 AS lignes FROM DUAL WHERE FALSE'),
        ') AS comptes WHERE lignes > 0 ORDER BY base, nom'
    )
    FROM (
        SELECT TABLE_SCHEMA AS base, CONCAT(TABLE_NAME, '.', CONSTRAINT_NAME) AS nom, CONCAT(
            'SELECT ', QUOTE(TABLE_SCHEMA), ' AS base, ', QUOTE(CONCAT(TABLE_NAME, '.', CONSTRAINT_NAME)), ' AS nom, COUNT(*) AS lignes',
            ' FROM `', REPLACE(TABLE_SCHEMA, '`', '``'), '`.`', REPLACE(TABLE_NAME, '`', '``'), '` AS enfant WHERE ',
            GROUP_CONCAT(CONCAT('enfant.`', REPLACE(COLUMN_NAME, '`', '``'), '` IS NOT NULL') ORDER BY ORDINAL_POSITION SEPARATOR ' AND '),
            ' AND NOT EXISTS (SELECT 1 FROM `', REPLACE(REFERENCED_TABLE_SCHEMA, '`', '``'), '`.`', REPLACE(REFERENCED_TABLE_NAME, '`', '``'), '` AS parent WHERE ',
            GROUP_CONCAT(CONCAT('parent.`', REPLACE(REFERENCED_COLUMN_NAME, '`', '``'), '` = enfant.`', REPLACE(COLUMN_NAME, '`', '``'), '`') ORDER BY ORDINAL_POSITION SEPARATOR ' AND '),
            ')'
        ) AS compte
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE REFERENCED_TABLE_NAME IS NOT NULL
            AND TABLE_SCHEMA NOT IN ('mysql', 'sys', 'information_schema', 'performance_schema')
            AND (@seule_base IS NULL OR TABLE_SCHEMA = @seule_base)
        GROUP BY TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME
    ) AS cles
);

PREPARE orphelines FROM @orphelines;
EXECUTE orphelines;
DEALLOCATE PREPARE orphelines;
