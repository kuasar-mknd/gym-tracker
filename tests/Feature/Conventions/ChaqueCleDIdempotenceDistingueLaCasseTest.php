<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * Chaque clé d'idempotence se compare en `ascii_bin` (#2097).
 *
 * Une clé d'idempotence nomme une tentative : l'action la cherche sous son
 * parent, et l'index unique tranche entre deux rejeux. Sous la collation des
 * tables, `utf8mb4_unicode_ci`, deux clés qui ne diffèrent que par la casse
 * sont une seule clé : la seconde tentative recevait la série, la ligne ou la
 * séance de la première, et l'index refusait de les tenir côte à côte. Une
 * égalité d'identité ne se confie pas à une collation insensible à la casse
 * (`.ai/rules/actions.md`) : chaque colonne qui porte une clé d'idempotence
 * est en `ascii` / `ascii_bin`, le jeu de caractères de
 * `CreateSetAction::idempotencyKey()`.
 *
 * `ascii_bin` tient encore une espace finale pour insignifiante (PAD SPACE,
 * comme `utf8mb4_unicode_ci` ; seules `binary` et les collations UCA 9.0.0,
 * `…_0900_…`, sont NO PAD) : c'est le motif de la clé, sans espace, qui
 * l'écarte, pas la colonne.
 *
 * La garde lit la base des tests, montée du schéma figé puis des migrations
 * d'après, dans `information_schema` : une nouvelle colonne de clé (son nom
 * porte « idempotency », ou en français « idempotence ») y entre d'elle-même.
 */

/**
 * Les colonnes de clé d'idempotence de la base des tests, sous la forme
 * `table.colonne`, avec leur jeu de caractères et leur collation.
 *
 * @return array<string, string>
 */
function cleDIdempotenceLesColonnes(): array
{
    $colonnes = [];

    foreach (DB::select("select table_name as nom_de_table, column_name as colonne, character_set_name as jeu, collation_name as collation from information_schema.columns where table_schema = database() and column_name like '%idempoten%' order by table_name, column_name") as $ligne) {
        $table = data_get($ligne, 'nom_de_table');
        $colonne = data_get($ligne, 'colonne');
        $jeu = data_get($ligne, 'jeu');
        $collation = data_get($ligne, 'collation');

        if (is_string($table) && is_string($colonne)) {
            $colonnes["{$table}.{$colonne}"] = sprintf('%s / %s', is_string($jeu) ? $jeu : '(aucun)', is_string($collation) ? $collation : '(aucune)');
        }
    }

    return $colonnes;
}

it('compare chaque clé d’idempotence en ascii_bin', function (): void {
    $colonnes = cleDIdempotenceLesColonnes();

    expect(array_filter($colonnes, static fn (string $forme): bool => $forme !== 'ascii / ascii_bin'))->toBe([], sprintf(
        "Ces colonnes de clé d'idempotence ne distinguent pas la casse : deux tentatives y seraient une seule.\n"
        ."Passez-les en ascii / ascii_bin par une migration à gardes (voir 2026_10_22_073017).\n%s",
        json_encode($colonnes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ));
});

it('trouve les clés d’idempotence des séances, des lignes et des séries', function (): void {
    expect(array_keys(cleDIdempotenceLesColonnes()))->toContain('workouts.idempotency_key', 'workout_lines.idempotency_key', 'sets.idempotency_key');
});
