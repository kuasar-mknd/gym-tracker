<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Chaque clé étrangère du schéma refuse une ligne dont le parent n'existe
 * pas, sur le serveur qui fait tourner la suite : en CI, MySQL 8.4 et 9.7.2
 * (#2095).
 *
 * MySQL 9.7.2 ne vérifie plus une clé étrangère quand l'index qui la porte a
 * une colonne nulle dans la ligne écrite, même une colonne hors de la clé :
 * une série s'écrivait ainsi sous une ligne de séance qui n'existe pas, que
 * 8.4 refuse (« 1452 … a foreign key constraint fails »). L'index qui porte
 * la clé est le premier de ceux qui commencent par ses colonnes, dans l'ordre
 * que la table tient de son histoire : mesuré sur 9.7.2, les deux mêmes
 * index déclarés dans l'ordre inverse, la clé est vérifiée d'un côté, pas de
 * l'autre (`.ai/rules/database.md`).
 *
 * Le premier cas essaie le serveur. Les clés se lisent dans
 * `information_schema` : une clé étrangère nouvelle y entre d'elle-même. La
 * ligne orpheline laisse nulle chaque colonne nullable des index qui
 * commencent par la clé ; les autres clés de la ligne visent un parent écrit
 * pour l'occasion, ou restent nulles, si bien qu'un refus 1452 ne peut venir
 * que de la clé essayée ; une colonne NOT NULL sans défaut reçoit une valeur
 * de son type, neuve à chaque tirage, donc unique dans un index unique. La
 * valeur de la clé essayée se tire une fois ces parents écrits, et absente de
 * sa table parente : leur chaîne peut y écrire (une prise de complément vise
 * un complément, qui vise un compte). Un autre refus que 1452 est rapporté
 * tel quel. Chaque essai se fait sous un point de sauvegarde, défait aussitôt.
 *
 * Le deuxième lit le schéma : aucun index qui commence par les colonnes d'une
 * clé étrangère n'a de colonne nullable derrière elles. La base du test naît
 * du schéma figé, puis des migrations d'après ; une base plus ancienne a pu
 * créer ses index dans un autre ordre (par les migrations d'avant le schéma
 * figé, retirées depuis), et y porter la clé par un autre index. Seul un
 * schéma dont aucun candidat n'a de colonne nulle ne dépend pas de ce choix.
 *
 * Les suivants éprouvent le premier. Une table taillée comme la reproduction
 * de l'issue lui donne le verdict que le serveur donne à l'insertion directe,
 * et la colonne nulle mise en tête est refusée partout ; sa colonne nulle a
 * un défaut non nul, que seule la mise à NULL de la garde écarte. Une ligne
 * qu'une contrainte CHECK refuse avant la clé est rapportée comme un refus
 * inattendu. Une ligne dont un autre parent fait écrire la table de la clé
 * essayée reste orpheline. Ces tables se créent dans la base du test : un
 * ordre DDL valide la transaction de `RefreshDatabase`, et ce fichier retire
 * lui-même ce qu'il a créé, puis en rouvre une (`.ai/rules/tests.md`).
 */

/**
 * Le 1er janvier 2026 à minuit (UTC), premier instant des dates tirées.
 */
const CLE_ETRANGERE_PREMIER_JOUR = 1_767_225_600;

/**
 * Les clés étrangères, les colonnes et les index des tables de la base,
 * lus en une fois.
 *
 * @return array{
 *     cles: list<array{table: string, nom: string, colonnes: list<string>, parent: string, colonnesDuParent: list<string>}>,
 *     colonnes: array<string, array<string, array{nulle: bool, defaut: bool, auto: bool, generee: bool, type: string, typeComplet: string, longueur: int|null, precision: int|null, echelle: int|null}>>,
 *     index: array<string, array<string, list<string|null>>>
 * }
 */
function cleEtrangereLeSchema(): array
{
    $cles = [];

    foreach (DB::select('select table_name as enfant, constraint_name as nom, column_name as colonne, referenced_table_name as parent, referenced_column_name as colonne_du_parent from information_schema.key_column_usage where table_schema = database() and referenced_table_name is not null order by table_name, constraint_name, ordinal_position') as $ligne) {
        $enfant = data_get($ligne, 'enfant');
        $nom = data_get($ligne, 'nom');
        $colonne = data_get($ligne, 'colonne');
        $parent = data_get($ligne, 'parent');
        $colonneDuParent = data_get($ligne, 'colonne_du_parent');

        if (! is_string($enfant) || ! is_string($nom) || ! is_string($colonne) || ! is_string($parent) || ! is_string($colonneDuParent)) {
            throw new RuntimeException('Une clé étrangère de information_schema.key_column_usage ne se lit pas.');
        }

        $cles["{$enfant}.{$nom}"] ??= ['table' => $enfant, 'nom' => $nom, 'colonnes' => [], 'parent' => $parent, 'colonnesDuParent' => []];
        $cles["{$enfant}.{$nom}"]['colonnes'][] = $colonne;
        $cles["{$enfant}.{$nom}"]['colonnesDuParent'][] = $colonneDuParent;
    }

    $colonnes = [];

    foreach (DB::select('select table_name as nom_de_table, column_name as nom, is_nullable as nulle, column_default as defaut, extra as extra, generation_expression as expression, data_type as type, column_type as type_complet, character_maximum_length as longueur, numeric_precision as `precision`, numeric_scale as echelle from information_schema.columns where table_schema = database() order by table_name, ordinal_position') as $ligne) {
        $table = data_get($ligne, 'nom_de_table');
        $nom = data_get($ligne, 'nom');
        $type = data_get($ligne, 'type');
        $typeComplet = data_get($ligne, 'type_complet');
        $extra = data_get($ligne, 'extra');

        if (! is_string($table) || ! is_string($nom) || ! is_string($type) || ! is_string($typeComplet) || ! is_string($extra)) {
            throw new RuntimeException('Une colonne de information_schema.columns ne se lit pas.');
        }

        $colonnes[$table][$nom] = [
            'nulle' => data_get($ligne, 'nulle') === 'YES',
            'defaut' => data_get($ligne, 'defaut') !== null,
            'auto' => str_contains(mb_strtolower($extra), 'auto_increment'),
            'generee' => is_string($expression = data_get($ligne, 'expression')) && $expression !== '',
            'type' => mb_strtolower($type),
            'typeComplet' => $typeComplet,
            'longueur' => cleEtrangereUnEntier(data_get($ligne, 'longueur')),
            'precision' => cleEtrangereUnEntier(data_get($ligne, 'precision')),
            'echelle' => cleEtrangereUnEntier(data_get($ligne, 'echelle')),
        ];
    }

    $index = [];

    foreach (DB::select('select table_name as nom_de_table, index_name as nom, column_name as colonne from information_schema.statistics where table_schema = database() order by table_name, index_name, seq_in_index') as $ligne) {
        $table = data_get($ligne, 'nom_de_table');
        $nom = data_get($ligne, 'nom');
        $colonne = data_get($ligne, 'colonne');

        if (! is_string($table) || ! is_string($nom)) {
            throw new RuntimeException('Un index de information_schema.statistics ne se lit pas.');
        }

        $index[$table][$nom][] = is_string($colonne) ? $colonne : null;
    }

    return ['cles' => array_values($cles), 'colonnes' => $colonnes, 'index' => $index];
}

/**
 * Un entier de `information_schema`, que le pilote rend en chaîne ou en
 * nombre selon le serveur.
 */
function cleEtrangereUnEntier(mixed $valeur): ?int
{
    if (is_int($valeur)) {
        return $valeur;
    }

    return is_string($valeur) && ctype_digit($valeur) ? (int) $valeur : null;
}

/**
 * Une valeur que la colonne accepte, neuve à chaque appel.
 *
 * @param  array{nulle: bool, defaut: bool, auto: bool, generee: bool, type: string, typeComplet: string, longueur: int|null, precision: int|null, echelle: int|null}  $colonne
 */
function cleEtrangereUneValeur(string $nom, array $colonne): int|float|string
{
    /** @var int $tirage */
    static $tirage = 0;
    $tirage++;

    $longueur = max(1, $colonne['longueur'] ?? 8);
    $entiers = ($colonne['precision'] ?? 10) - ($colonne['echelle'] ?? 0);

    return match ($colonne['type']) {
        'tinyint' => $tirage % 100 + 1,
        'smallint' => $tirage % 30_000 + 1,
        'mediumint', 'int', 'integer', 'bigint' => $tirage,
        'decimal' => $entiers > 0 ? $tirage % (10 ** min($entiers, 9) - 1) + 1 : 0,
        'float', 'double' => (float) $tirage,
        'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext' => mb_substr(mb_str_pad(base_convert((string) $tirage, 10, 36), min($longueur, 8), '0', STR_PAD_LEFT), -min($longueur, 8)),
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob' => mb_substr(str_pad(pack('J', $tirage), min($longueur, 16), "\0", STR_PAD_LEFT), -min($longueur, 16), null, '8bit'),
        'json' => '[]',
        'date' => gmdate('Y-m-d', CLE_ETRANGERE_PREMIER_JOUR + $tirage * 86_400),
        'datetime', 'timestamp' => gmdate('Y-m-d H:i:s', CLE_ETRANGERE_PREMIER_JOUR + $tirage),
        'time' => gmdate('H:i:s', $tirage % 86_400),
        'year' => 1901 + $tirage % 254,
        'enum', 'set' => preg_match("/^(?:enum|set)\\('((?:[^']|'')*)'/i", $colonne['typeComplet'], $choix) === 1 ? str_replace("''", "'", $choix[1]) : throw new RuntimeException("Colonne {$nom} : choix illisibles ({$colonne['typeComplet']})."),
        default => throw new RuntimeException("Colonne {$nom} : type {$colonne['typeComplet']} sans valeur prévue. L'ajouter à cleEtrangereUneValeur()."),
    };
}

/**
 * Écrit une ligne en nommant ses colonnes, chacune par un paramètre.
 *
 * @param  array<string, int|float|string|null>  $valeurs
 */
function cleEtrangereEcrire(string $table, array $valeurs): void
{
    $colonnes = implode(', ', array_map(static fn (string $nom): string => '`'.str_replace('`', '``', $nom).'`', array_keys($valeurs)));
    $parametres = implode(', ', array_fill(0, count($valeurs), '?'));

    DB::insert('insert into `'.str_replace('`', '``', $table)."` ({$colonnes}) values ({$parametres})", array_values($valeurs));
}

/**
 * Les valeurs des autres colonnes que la ligne doit porter : chaque clé
 * étrangère vers un parent existant (écrit au besoin) ou nulle, chaque
 * colonne NOT NULL sans défaut d'une valeur de son type. Les colonnes déjà
 * posées ne changent pas.
 *
 * @param  array{
 *     cles: list<array{table: string, nom: string, colonnes: list<string>, parent: string, colonnesDuParent: list<string>}>,
 *     colonnes: array<string, array<string, array{nulle: bool, defaut: bool, auto: bool, generee: bool, type: string, typeComplet: string, longueur: int|null, precision: int|null, echelle: int|null}>>,
 *     index: array<string, array<string, list<string|null>>>
 * }  $schema
 * @param  array<string, int|float|string|null>  $valeurs
 * @param  list<string>  $aRemplir  des colonnes à remplir même nullables ou avec un défaut
 * @return array<string, int|float|string|null>
 */
function cleEtrangereCompleter(array $schema, string $table, array $valeurs, array $aRemplir, int $profondeur): array
{
    foreach ($schema['cles'] as $cle) {
        if ($cle['table'] !== $table || array_intersect($cle['colonnes'], array_keys($valeurs)) !== []) {
            continue;
        }

        $obligatoire = array_filter($cle['colonnes'], static fn (string $colonne): bool => ! ($schema['colonnes'][$table][$colonne]['nulle'] ?? true) || in_array($colonne, $aRemplir, true)) !== [];

        if (! $obligatoire) {
            foreach ($cle['colonnes'] as $colonne) {
                $valeurs[$colonne] = null;
            }

            continue;
        }

        $parent = cleEtrangereUneLigneValide($schema, $cle['parent'], $cle['colonnesDuParent'], $profondeur + 1);

        foreach ($cle['colonnes'] as $position => $colonne) {
            $valeurs[$colonne] = $parent[$cle['colonnesDuParent'][$position]] ?? null;
        }
    }

    foreach ($schema['colonnes'][$table] ?? [] as $nom => $colonne) {
        if (array_key_exists($nom, $valeurs) || $colonne['auto'] || $colonne['generee']) {
            continue;
        }

        if ((! $colonne['nulle'] && ! $colonne['defaut']) || in_array($nom, $aRemplir, true)) {
            $valeurs[$nom] = cleEtrangereUneValeur("{$table}.{$nom}", $colonne);
        }
    }

    return $valeurs;
}

/**
 * Écrit dans la table une ligne que chacune de ses contraintes accepte, et
 * rend la valeur des colonnes demandées (celles que vise la clé d'un enfant).
 *
 * @param  array{
 *     cles: list<array{table: string, nom: string, colonnes: list<string>, parent: string, colonnesDuParent: list<string>}>,
 *     colonnes: array<string, array<string, array{nulle: bool, defaut: bool, auto: bool, generee: bool, type: string, typeComplet: string, longueur: int|null, precision: int|null, echelle: int|null}>>,
 *     index: array<string, array<string, list<string|null>>>
 * }  $schema
 * @param  list<string>  $aRendre
 * @return array<string, int|float|string|null>
 */
function cleEtrangereUneLigneValide(array $schema, string $table, array $aRendre, int $profondeur): array
{
    if ($profondeur > 6) {
        throw new RuntimeException("La chaîne des parents obligatoires ne finit pas après {$table}.");
    }

    $numerotees = array_filter($aRendre, static fn (string $colonne): bool => $schema['colonnes'][$table][$colonne]['auto'] ?? false);
    $valeurs = cleEtrangereCompleter($schema, $table, [], array_values(array_diff($aRendre, $numerotees)), $profondeur);

    cleEtrangereEcrire($table, $valeurs);

    foreach ($numerotees as $colonne) {
        $valeurs[$colonne] = (int) DB::getPdo()->lastInsertId();
    }

    return $valeurs;
}

/**
 * Pour chaque index qui commence par les colonnes de la clé, les colonnes
 * nullables qu'il porte derrière elles.
 *
 * @param  array{
 *     cles: list<array{table: string, nom: string, colonnes: list<string>, parent: string, colonnesDuParent: list<string>}>,
 *     colonnes: array<string, array<string, array{nulle: bool, defaut: bool, auto: bool, generee: bool, type: string, typeComplet: string, longueur: int|null, precision: int|null, echelle: int|null}>>,
 *     index: array<string, array<string, list<string|null>>>
 * }  $schema
 * @param  array{table: string, nom: string, colonnes: list<string>, parent: string, colonnesDuParent: list<string>}  $cle
 * @return array<string, list<string>>
 */
function cleEtrangereLesColonnesNullesDerriere(array $schema, array $cle): array
{
    $table = $cle['table'];
    $parIndex = [];

    foreach ($schema['index'][$table] ?? [] as $nom => $colonnesDeLIndex) {
        $tete = array_slice($colonnesDeLIndex, 0, count($cle['colonnes']));

        if (count($tete) !== count($cle['colonnes']) || array_diff($cle['colonnes'], $tete) !== []) {
            continue;
        }

        $parIndex[$nom] = array_values(array_filter(
            array_slice($colonnesDeLIndex, count($cle['colonnes'])),
            static fn (?string $colonne): bool => $colonne !== null && ! in_array($colonne, $cle['colonnes'], true) && ($schema['colonnes'][$table][$colonne]['nulle'] ?? false),
        ));
    }

    return $parIndex;
}

/**
 * Essaie d'écrire, sous la clé, une ligne dont le parent n'existe pas,
 * chaque colonne nullable des index qui commencent par ses colonnes laissée
 * nulle. Rend `null` quand le serveur la refuse en 1452, sinon ce qui s'est
 * passé.
 *
 * @param  array{
 *     cles: list<array{table: string, nom: string, colonnes: list<string>, parent: string, colonnesDuParent: list<string>}>,
 *     colonnes: array<string, array<string, array{nulle: bool, defaut: bool, auto: bool, generee: bool, type: string, typeComplet: string, longueur: int|null, precision: int|null, echelle: int|null}>>,
 *     index: array<string, array<string, list<string|null>>>
 * }  $schema
 * @param  array{table: string, nom: string, colonnes: list<string>, parent: string, colonnesDuParent: list<string>}  $cle
 */
function cleEtrangereTenterUnOrphelin(array $schema, array $cle): ?string
{
    $table = $cle['table'];
    $nulles = array_fill_keys(array_merge([], ...array_values(cleEtrangereLesColonnesNullesDerriere($schema, $cle))), null);

    DB::beginTransaction();

    try {
        // Les parents que la ligne vise par ses autres clés s'écrivent
        // d'abord : leur chaîne peut écrire dans la table de la clé essayée,
        // et la valeur « orpheline », tirée ensuite, n'y a donc pas de parent.
        $valeurs = cleEtrangereCompleter($schema, $table, array_fill_keys($cle['colonnes'], null) + $nulles, [], 0);
        $orpheline = [];

        for ($essai = 0; $essai < 10; $essai++) {
            $orpheline = [];

            foreach ($cle['colonnes'] as $colonne) {
                $orpheline[$colonne] = cleEtrangereUneValeur("{$table}.{$colonne}", $schema['colonnes'][$table][$colonne] ?? throw new RuntimeException("Colonne {$table}.{$colonne} introuvable."));
            }

            $conditions = implode(' and ', array_map(static fn (string $colonne): string => '`'.str_replace('`', '``', $colonne).'` = ?', $cle['colonnesDuParent']));

            if (DB::selectOne('select 1 as present from `'.str_replace('`', '``', $cle['parent'])."` where {$conditions} limit 1", array_values($orpheline)) === null) {
                break;
            }
        }

        cleEtrangereEcrire($table, $orpheline + $valeurs);

        return sprintf(
            'ligne orpheline acceptée%s',
            $nulles === [] ? '' : ', '.implode(', ', array_keys($nulles)).' laissées nulles',
        );
    } catch (QueryException $erreur) {
        $code = $erreur->errorInfo[1] ?? null;

        return $code === 1452 ? null : sprintf('refus inattendu (%s) : %s', is_scalar($code) ? (string) $code : '?', $erreur->getMessage());
    } finally {
        DB::rollBack();
    }
}

/**
 * Retire de la base du test les tables que les cas qui éprouvent la garde
 * créent (`orphelin97_…`), clés étrangères coupées : une table visée par une
 * autre ne se retire pas d'abord sans cela.
 */
function cleEtrangereRetirerLesTablesDuCas(): void
{
    $tables = array_values(array_filter(
        array_map(static fn (mixed $ligne): mixed => data_get($ligne, 'nom'), DB::select("select table_name as nom from information_schema.tables where table_schema = database() and table_name like 'orphelin97\\_%'")),
        is_string(...),
    ));

    if ($tables === []) {
        return;
    }

    Schema::withoutForeignKeyConstraints(static function () use ($tables): void {
        foreach ($tables as $table) {
            DB::statement('drop table `'.str_replace('`', '``', $table).'`');
        }
    });
}

/**
 * La clé étrangère de ce nom, lue dans le schéma.
 *
 * @param  array{
 *     cles: list<array{table: string, nom: string, colonnes: list<string>, parent: string, colonnesDuParent: list<string>}>,
 *     colonnes: array<string, array<string, array{nulle: bool, defaut: bool, auto: bool, generee: bool, type: string, typeComplet: string, longueur: int|null, precision: int|null, echelle: int|null}>>,
 *     index: array<string, array<string, list<string|null>>>
 * }  $schema
 * @return array{table: string, nom: string, colonnes: list<string>, parent: string, colonnesDuParent: list<string>}
 */
function cleEtrangereLaCle(array $schema, string $nom): array
{
    $cles = array_values(array_filter($schema['cles'], static fn (array $cle): bool => $cle['nom'] === $nom));

    if (count($cles) !== 1) {
        throw new RuntimeException(sprintf('%d clés étrangères nommées %s dans information_schema, une attendue.', count($cles), $nom));
    }

    return $cles[0];
}

afterEach(function (): void {
    cleEtrangereRetirerLesTablesDuCas();

    if (! DB::getPdo()->inTransaction()) {
        DB::getPdo()->beginTransaction();
    }
});

it('refuse, sous chaque clé étrangère, une ligne dont le parent n’existe pas', function (): void {
    expect(DB::scalar('select @@foreign_key_checks'))->toEqual(1, 'La session ne vérifie pas les clés étrangères : la garde ne prouverait rien.');

    $schema = cleEtrangereLeSchema();
    $laissentPasser = [];

    foreach ($schema['cles'] as $cle) {
        $verdict = cleEtrangereTenterUnOrphelin($schema, $cle);

        if ($verdict !== null) {
            $laissentPasser[] = sprintf('%s.%s (%s vers %s) : %s', $cle['table'], $cle['nom'], implode(', ', $cle['colonnes']), $cle['parent'], $verdict);
        }
    }

    $version = DB::scalar('select version()');

    expect($schema['cles'])->not->toBeEmpty('Aucune clé étrangère lue dans information_schema : la garde ne vérifierait rien.')
        ->and($laissentPasser)->toBe([], sprintf(
            "Sous MySQL %s, ces clés étrangères ne refusent pas une ligne orpheline :\n- %s\n\n"
            ."Un index qui commence par la colonne d'une clé étrangère ne met derrière elle que des colonnes "
            .'non nulles ; un index qui doit mêler une colonne nulle la met en tête, par une migration nouvelle '
            .'(`.ai/rules/database.md`).',
            is_scalar($version) ? (string) $version : '?',
            implode("\n- ", $laissentPasser),
        ));
});

it('ne laisse de colonne nullable derrière la clé dans aucun index qui commence par elle', function (): void {
    $schema = cleEtrangereLeSchema();
    $index = [];

    foreach ($schema['cles'] as $cle) {
        foreach (cleEtrangereLesColonnesNullesDerriere($schema, $cle) as $nom => $nulles) {
            if ($nulles !== []) {
                $index[] = sprintf(
                    '%s.%s (%s) : %s, nullable, derrière %s',
                    $cle['table'],
                    $nom,
                    implode(', ', array_map(static fn (?string $colonne): string => $colonne ?? '(expression)', $schema['index'][$cle['table']][$nom] ?? [])),
                    implode(', ', $nulles),
                    $cle['nom'],
                );
            }
        }
    }

    expect($index)->toBe([], sprintf(
        "Ces index commencent par la colonne d'une clé étrangère et portent une colonne nullable derrière elle :\n- %s\n\n"
        .'Sous MySQL 9.7.2, la clé n\'est plus vérifiée pour une ligne où cette colonne est nulle, si cet index la porte, '
        ."et lequel la porte dépend de l'ordre des index de la table. Mettre la colonne nulle en tête, ou ne garder derrière "
        .'la clé que des colonnes non nulles, par une migration nouvelle (`.ai/rules/database.md`).',
        implode("\n- ", $index),
    ));
});

it('voit une clé étrangère que le serveur ne vérifie pas', function (array $colonnesDeLIndex, bool $refuseeDePartout): void {
    // `n` a un défaut non nul : omise, elle ne serait pas nulle, et seule la
    // garde qui la met à NULL d'elle-même rend le verdict du serveur. L'index
    // est posé avant la clé, qui le prend s'il commence par sa colonne.
    DB::statement('create table orphelin97_parent (id bigint unsigned primary key)');
    Schema::create('orphelin97_enfant', static function (Blueprint $table) use ($colonnesDeLIndex): void {
        $table->id();
        $table->unsignedBigInteger('parent_id');
        $table->integer('n')->nullable()->default(0);
        $table->index($colonnesDeLIndex, 'orphelin97_i');
        $table->foreign('parent_id', 'orphelin97_fk')->references('id')->on('orphelin97_parent');
    });
    DB::getPdo()->beginTransaction();

    DB::beginTransaction();

    try {
        DB::insert('insert into orphelin97_enfant (parent_id, n) values (0, null)');
        $leServeurRefuse = false;
    } catch (QueryException $erreur) {
        $leServeurRefuse = ($erreur->errorInfo[1] ?? null) === 1452;
    } finally {
        DB::rollBack();
    }

    $schema = cleEtrangereLeSchema();

    expect(cleEtrangereTenterUnOrphelin($schema, cleEtrangereLaCle($schema, 'orphelin97_fk')) === null)->toBe($leServeurRefuse);

    if ($refuseeDePartout) {
        expect($leServeurRefuse)->toBeTrue();
    }
})->with([
    'une colonne nulle derrière la clé, comme la reproduction de #2095' => [['parent_id', 'n'], false],
    'la colonne nulle en tête' => [['n', 'parent_id'], true],
]);

it('ne compte pour la clé qu’un refus 1452', function (): void {
    // La contrainte CHECK refuse `n` nulle, que la garde met à NULL parce
    // qu'elle suit la clé dans un index : le serveur refuse la ligne avant
    // d'en lire la clé (3819), et la garde doit le dire, pas y voir la clé.
    DB::statement('create table orphelin97_parent (id bigint unsigned primary key)');
    DB::statement('create table orphelin97_enfant (id bigint unsigned auto_increment primary key, parent_id bigint unsigned not null, n int null default 0, key orphelin97_i (parent_id, n), constraint orphelin97_n check (n is not null), constraint orphelin97_fk foreign key (parent_id) references orphelin97_parent (id))');
    DB::getPdo()->beginTransaction();

    $schema = cleEtrangereLeSchema();

    expect(cleEtrangereTenterUnOrphelin($schema, cleEtrangereLaCle($schema, 'orphelin97_fk')))->toBeString()->toStartWith('refus inattendu (3819) : ');
});

it('tire la valeur orpheline une fois écrits les parents que la ligne fait écrire', function (): void {
    // La ligne vise un parent par une autre clé, `milieu_id`, dont la ligne
    // écrite pour l'occasion vise à son tour la table de la clé essayée : la
    // garde y écrit une ligne. Sa numérotation commence à la valeur que le
    // prochain tirage rendra ; tirée avant cette écriture, la valeur
    // « orpheline » aurait un parent, et la ligne serait acceptée.
    $prochainTirage = cleEtrangereUneValeur('sonde.entier', ['nulle' => false, 'defaut' => false, 'auto' => false, 'generee' => false, 'type' => 'bigint', 'typeComplet' => 'bigint unsigned', 'longueur' => null, 'precision' => 20, 'echelle' => 0]);
    assert(is_int($prochainTirage));
    $prochainTirage++;

    Schema::create('orphelin97_parent', static function (Blueprint $table) use ($prochainTirage): void {
        $table->id()->startingValue($prochainTirage);
    });
    DB::statement('create table orphelin97_milieu (id bigint unsigned auto_increment primary key, parent_id bigint unsigned not null, constraint orphelin97_fk_milieu foreign key (parent_id) references orphelin97_parent (id))');
    DB::statement('create table orphelin97_enfant (id bigint unsigned auto_increment primary key, parent_id bigint unsigned not null, milieu_id bigint unsigned not null, constraint orphelin97_fk foreign key (parent_id) references orphelin97_parent (id), constraint orphelin97_fk_enfant_milieu foreign key (milieu_id) references orphelin97_milieu (id))');
    DB::getPdo()->beginTransaction();

    $schema = cleEtrangereLeSchema();

    expect(cleEtrangereTenterUnOrphelin($schema, cleEtrangereLaCle($schema, 'orphelin97_fk')))->toBeNull();
});
