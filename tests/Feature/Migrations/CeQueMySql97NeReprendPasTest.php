<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * `docker/mysql/avant-mysql-9-7.sql` est la vérification de la bascule vers
 * MySQL 9.7 (`.ai/rules/dependencies.md`, étapes 2 et 3) : sous 8.4, elle doit
 * nommer chaque objet qui appelle `md5()`, `sha1()` ou `sha()`, que 9.7 ne
 * fournit plus en fonctions natives, et ne rien rendre pour le schéma de
 * l'application. Chaque forme se crée dans la base du test : un ordre DDL
 * valide la transaction de `RefreshDatabase`, et ce fichier retire lui-même ce
 * qu'il a créé avant d'en rouvrir une (`.ai/rules/tests.md`). Un déclencheur ne
 * se crée pas sans le privilège SUPER quand le journal binaire est actif,
 * comme sur le service de la CI : la requête le lit (`information_schema`
 * `.TRIGGERS`), mais aucun cas ne le crée.
 */

/**
 * Ce que la vérification trouve dans la base du test, chaque objet en
 * « objet : nom ».
 *
 * @return list<string>
 */
function avantMysql97Trouvailles(): array
{
    $ordres = preg_split('/;\s*$/m', (string) file_get_contents(base_path('docker/mysql/avant-mysql-9-7.sql')));
    $ordres = array_values(array_filter(
        array_map(trim(...), $ordres === false ? [] : $ordres),
        static fn (string $ordre): bool => $ordre !== '',
    ));
    $requete = array_pop($ordres);
    assert(is_string($requete));

    foreach ($ordres as $ordre) {
        DB::statement($ordre);
    }

    $base = DB::connection()->getDatabaseName();
    $trouvailles = [];

    foreach (DB::select($requete) as $ligne) {
        $objet = data_get($ligne, 'objet');
        $nom = data_get($ligne, 'nom');

        if (data_get($ligne, 'base') === $base && is_string($objet) && is_string($nom)) {
            $trouvailles[] = "{$objet} : {$nom}";
        }
    }

    return $trouvailles;
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
    expect(avantMysql97Trouvailles())->toBe([]);
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
