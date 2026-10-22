<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * `docker/mysql/avant-mysql-9-7.sql` est la vérification de la bascule vers
 * MySQL 9.7 (`.ai/rules/dependencies.md`, étapes 2 et 3) : sous 8.4, elle doit
 * nommer chaque objet qui appelle `md5()`, `sha1()` ou `sha()`, que 9.7 ne
 * fournit plus en fonctions natives, puis compter, sous chaque clé étrangère,
 * les lignes dont le parent n'existe pas, qu'un essai sous 9.7 a pu écrire
 * (#2095), avec la requête qui les liste, et ne rien rendre pour le schéma de
 * l'application. Chaque forme se
 * crée dans la base du test : un ordre DDL valide la transaction de
 * `RefreshDatabase`, et ce fichier retire lui-même ce qu'il a créé avant d'en
 * rouvrir une (`.ai/rules/tests.md`). Un déclencheur ne se crée pas sans le
 * privilège SUPER quand le journal binaire est actif, comme sur le service de
 * la CI : la requête le lit (`information_schema` `.TRIGGERS`), mais aucun cas
 * ne le crée. La vérification ne lit que la base du test (`@seule_base`) : le
 * serveur en porte d'autres, dont elle compterait les lignes.
 */

/**
 * Ce que la vérification rend pour la base du test : chaque objet qui appelle
 * ces fonctions en « objet : nom », chaque clé étrangère qui a des lignes
 * orphelines en « nom : lignes », et la requête qu'elle donne pour les lister,
 * par clé. Les ordres se lancent un à un, comme le client `mysql` les lit, et
 * chaque ordre qui rend des lignes en donne un résultat : les appels d'abord,
 * les orphelines ensuite.
 *
 * @return array{appels: list<string>, orphelines: list<string>, pourLesLister: array<string, string>}
 */
function avantMysql97Verifier(): array
{
    $ordres = preg_split('/;\s*$/m', (string) file_get_contents(base_path('docker/mysql/avant-mysql-9-7.sql')));
    $ordres = array_values(array_filter(
        array_map(trim(...), $ordres === false ? [] : $ordres),
        static fn (string $ordre): bool => $ordre !== '',
    ));

    $base = DB::connection()->getDatabaseName();
    DB::statement('set @seule_base = database()');
    $resultats = [];

    foreach ($ordres as $ordre) {
        $requete = DB::getPdo()->query($ordre);

        if ($requete !== false && $requete->columnCount() > 0) {
            $resultats[] = array_values(array_filter(
                $requete->fetchAll(PDO::FETCH_ASSOC),
                static fn (mixed $ligne): bool => is_array($ligne) && ($ligne['base'] ?? null) === $base,
            ));
        }
    }

    expect($resultats)->toHaveCount(2);

    $appels = [];
    $orphelines = [];
    $pourLesLister = [];

    foreach ($resultats[0] as $ligne) {
        $appels[] = sprintf('%s : %s', avantMysql97Texte($ligne['objet'] ?? null), avantMysql97Texte($ligne['nom'] ?? null));
    }

    foreach ($resultats[1] as $ligne) {
        $nom = avantMysql97Texte($ligne['nom'] ?? null);
        $orphelines[] = sprintf('%s : %s', $nom, avantMysql97Texte($ligne['lignes'] ?? null));
        $pourLesLister[$nom] = avantMysql97Texte($ligne['pour_les_lister'] ?? null);
    }

    return ['appels' => $appels, 'orphelines' => $orphelines, 'pourLesLister' => $pourLesLister];
}

/**
 * Ce que la vérification trouve dans la base du test, chaque objet en
 * « objet : nom ».
 *
 * @return list<string>
 */
function avantMysql97Trouvailles(): array
{
    return avantMysql97Verifier()['appels'];
}

/**
 * Une valeur rendue par le serveur, en texte.
 */
function avantMysql97Texte(mixed $valeur): string
{
    return is_scalar($valeur) ? (string) $valeur : '?';
}

/**
 * Vrai quand le serveur fournit encore `md5()` en fonction native, comme
 * MySQL 8.4 : sous 9.7, une colonne qui l'appelle ne peut pas exister.
 */
function avantMysql97LeServeurCalculeMd5(): bool
{
    try {
        DB::select("select md5('a') as empreinte");

        return true;
    } catch (QueryException) {
        return false;
    }
}

/**
 * Retire de la base du test chaque objet que ces cas créent (`avant97_…`).
 */
function avantMysql97Retirer(): void
{
    Schema::withoutForeignKeyConstraints(avantMysql97RetirerLesObjets(...));
}

/**
 * Retire les objets, les clés étrangères coupées : une table visée par une
 * autre ne se retire pas d'abord sans cela.
 */
function avantMysql97RetirerLesObjets(): void
{
    DB::statement('drop procedure if exists avant97_p');
    DB::statement('drop event if exists avant97_e');

    foreach (['VIEW' => 'view', 'BASE TABLE' => 'table'] as $type => $ordre) {
        $objets = DB::select("select table_name as nom from information_schema.tables where table_schema = database() and table_type = ? and table_name like 'avant97\\_%'", [$type]);

        foreach ($objets as $objet) {
            $nom = data_get($objet, 'nom');

            if (is_string($nom)) {
                DB::statement("drop {$ordre} `{$nom}`");
            }
        }
    }
}

afterEach(function (): void {
    if (DB::getPdo()->inTransaction()) {
        return;
    }

    avantMysql97Retirer();

    DB::getPdo()->beginTransaction();
});

it('ne trouve rien dans le schéma de l’application', function (): void {
    expect(avantMysql97Verifier())->toBe(['appels' => [], 'orphelines' => [], 'pourLesLister' => []]);
});

it('compte les lignes orphelines de chaque clé étrangère, donne la requête qui les liste, et rien sous une clé que la ligne ne vise pas', function (): void {
    DB::statement('create table avant97_parent (id int primary key, a int not null, b int not null, unique key avant97_ab (a, b))');
    DB::statement('create table avant97_enfant (id int primary key, parent_id int null, a int null, b int null, constraint avant97_fk foreign key (parent_id) references avant97_parent (id), constraint avant97_fk_ab foreign key (a, b) references avant97_parent (a, b))');
    DB::statement('create table avant97_sain (id int primary key, parent_id int not null, constraint avant97_fk_sain foreign key (parent_id) references avant97_parent (id))');
    DB::insert('insert into avant97_parent (id, a, b) values (1, 10, 20)');
    DB::insert('insert into avant97_sain (id, parent_id) values (1, 1)');

    Schema::withoutForeignKeyConstraints(static function (): void {
        DB::insert('insert into avant97_enfant (id, parent_id, a, b) values (1, 1, 10, 20), (2, 2, 10, 21), (3, 3, null, 20), (4, null, 11, 20)');
    });

    $verification = avantMysql97Verifier();
    $listees = [];

    foreach ($verification['pourLesLister'] as $nom => $requete) {
        $identifiants = array_map(static fn (mixed $ligne): string => avantMysql97Texte(data_get($ligne, 'id')), DB::select($requete));
        sort($identifiants);
        $listees[$nom] = $identifiants;
    }

    expect($verification['appels'])->toBe([])
        ->and($verification['orphelines'])->toBe(['avant97_enfant.avant97_fk : 2', 'avant97_enfant.avant97_fk_ab : 2'])
        ->and($listees)->toBe(['avant97_enfant.avant97_fk' => ['2', '3'], 'avant97_enfant.avant97_fk_ab' => ['2', '4']]);
});

it('nomme chaque objet que 9.7 ne reprendrait pas, et rien de ce qui appelle SHA2()', function (array $ordres, array $trouvailles): void {
    if (! avantMysql97LeServeurCalculeMd5()) {
        $this->markTestSkipped('Ce serveur ne fournit plus md5() en fonction native : ces objets ne peuvent pas y exister, et la vérification se lance sous 8.4.');
    }

    foreach ($ordres as $ordre) {
        assert(is_string($ordre));
        DB::statement($ordre);
    }

    expect(avantMysql97Trouvailles())->toBe($trouvailles);
})->with([
    'une colonne générée virtuelle indexée, par sha()' => [['create table avant97_t (id int primary key, k varchar(50) not null, h char(40) as (sha(k)) virtual, key (h))'], ['colonne générée : avant97_t.h']],
    'une colonne générée stockée, par SHA1(), que 8.4 réécrit en sha()' => [['create table avant97_t (id int primary key, k varchar(50) not null, h char(40) as (SHA1(k)) stored)'], ['colonne générée : avant97_t.h']],
    'l’empreinte de Pulse d’avant la migration' => [['create table avant97_t (id int primary key, `key` text not null, key_hash binary(16) as (unhex(md5(`key`))) virtual, unique (key_hash))'], ['colonne générée : avant97_t.key_hash']],
    'un défaut calculé' => [["create table avant97_t (id int primary key, k char(32) not null default (md5('x')))"], ['défaut calculé : avant97_t.k']],
    'un index fonctionnel, un espace avant la parenthèse' => [['create table avant97_t (id int primary key, k varchar(50) not null, key avant97_i ((MD5 (k))))'], ['index fonctionnel : avant97_t.avant97_i']],
    'une contrainte CHECK' => [['create table avant97_t (id int primary key, k varchar(50) not null, constraint avant97_c check (length(md5(k)) = 32))'], ['contrainte CHECK : avant97_t.avant97_c']],
    'une vue' => [['create table avant97_t (id int primary key, k varchar(50) not null)', 'create view avant97_v as select md5(k) as h from avant97_t'], ['vue : avant97_v']],
    'une procédure, en capitales et un espace avant la parenthèse' => [["CREATE PROCEDURE avant97_p() SELECT SHA1 ('a')"], ['routine : avant97_p']],
    'un événement' => [["create event avant97_e on schedule every 1 day disable do select md5('a')"], ['événement : avant97_e']],
    'accepté : SHA2() partout, des colonnes nommées md5 et sha, un défaut littéral et une fonction du schéma' => [[
        "create table avant97_t (id int primary key, md5 varchar(10), sha varchar(10), h binary(16) as (unhex(left(sha2(md5,256),32))) stored, g varchar(30) as (concat(md5, sha)) virtual, k char(64) default (sha2('x', 256)), l varchar(20) default 'md5(x)', key (h), key avant97_i ((sha2(sha,256))), constraint avant97_c check (length(sha2(md5, 224)) = 56))",
        'create view avant97_v as select sha2(md5, 256) as h, md5 from avant97_t',
        "create procedure avant97_p() select sha2('a', 256)",
        "create event avant97_e on schedule every 1 day disable do select avant97_md5('a')",
    ], []],
]);
