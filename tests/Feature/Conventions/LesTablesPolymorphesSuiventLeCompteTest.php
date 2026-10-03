<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Une table polymorphe ne s'oublie plus à la suppression d'un compte (#1935).
 *
 * Une relation polymorphe — un couple `*_type` / `*_id` — n'a pas de clé
 * étrangère : la base ne sait pas qu'une ligne désigne un compte, et
 * `ON DELETE CASCADE` ne peut rien pour elle. Quatre tables (notifications,
 * abonnements push, jetons d'API, journal d'activité), plus les deux tables
 * d'attribution de spatie/permission, gardaient ainsi en base ce qu'une
 * suppression de compte promettait d'effacer.
 *
 * Cette garde relit chaque colonne `*_type` de la base et exige qu'elle soit
 * soit dans l'inventaire que `User::delete()` efface
 * (`User::TRACES_POLYMORPHES`), soit dans les exceptions ci-dessous, avec la
 * raison. `SuppressionDuCompteTest` sème et vérifie chaque relation de
 * l'inventaire par chacun des chemins de suppression : une table qui y entre
 * est donc couverte par ce test, et la prochaine qui arrive fait tomber la
 * suite tant qu'on n'a pas décidé de son sort.
 *
 * La base lue est celle des tests, pas le seul fichier
 * database/schema/mysql-schema.sql : elle est montée depuis ce dump PUIS
 * depuis les migrations qui lui sont postérieures. La prochaine table
 * polymorphe arrivera par une migration, et n'entrera dans le dump qu'au
 * prochain écrasement ; lire le fichier seul la laisserait passer.
 */

/**
 * Les colonnes `*_type` qui ne désignent pas un propriétaire, et pourquoi.
 *
 * @return array<string, string>
 */
function tablesPolymorphesExceptions(): array
{
    return [
        'goals.measurement_type' => "Ce que l'objectif mesure (« weight », « body_fat », un tour de bras) : "
            ."une valeur, pas une relation. Aucune colonne measurement_id ne l'accompagne, "
            .'et la ligne suit déjà le compte par goals.user_id.',
    ];
}

/**
 * Les colonnes de la base de test, sous la forme `table.colonne`.
 *
 * Le nom de la base est passé explicitement : sans lui, MySQL rend les tables
 * de TOUTES les bases du serveur.
 *
 * @return list<string>
 */
function tablesPolymorphesColonnes(): array
{
    $connexion = DB::connection();
    $schema = $connexion->getSchemaBuilder();
    $colonnes = [];

    foreach ($schema->getTableListing($connexion->getDatabaseName(), false) as $table) {
        foreach ($schema->getColumnListing($table) as $colonne) {
            $colonnes[] = "{$table}.{$colonne}";
        }
    }

    return $colonnes;
}

/**
 * Les colonnes `*_type` que l'inventaire des traces efface.
 *
 * @return list<string>
 */
function tablesPolymorphesInventoriees(): array
{
    return array_map(
        fn (string $trace): string => "{$trace}_type",
        User::TRACES_POLYMORPHES,
    );
}

it('range chaque colonne *_type de la base dans l’inventaire des traces ou dans une exception justifiée', function (): void {
    $colonnesDeType = array_values(array_filter(
        tablesPolymorphesColonnes(),
        fn (string $colonne): bool => str_ends_with($colonne, '_type'),
    ));

    // Un schéma qui ne rend aucune colonne de type ferait passer la garde sans
    // rien vérifier : on sait qu'il y en a au moins une par table connue.
    expect($colonnesDeType)->toContain('notifications.notifiable_type');

    $decidees = [...tablesPolymorphesInventoriees(), ...array_keys(tablesPolymorphesExceptions())];
    $oubliees = array_values(array_diff($colonnesDeType, $decidees));

    expect($oubliees)->toBe(
        [],
        "Ces colonnes polymorphes peuvent désigner un compte sans clé étrangère :\n  - "
        .implode("\n  - ", $oubliees)
        ."\nAjouter la relation à User::TRACES_POLYMORPHES (et la semer dans SuppressionDuCompteTest), "
        .'ou la déclarer dans tablesPolymorphesExceptions() avec sa raison.',
    );
});

it('ne garde dans l’inventaire et les exceptions que des colonnes qui existent', function (): void {
    $colonnes = tablesPolymorphesColonnes();

    foreach (User::TRACES_POLYMORPHES as $trace) {
        expect($colonnes)
            ->toContain("{$trace}_type")
            ->toContain("{$trace}_id");
    }

    foreach (tablesPolymorphesExceptions() as $colonne => $raison) {
        expect($colonnes)->toContain($colonne)
            ->and(trim($raison))->not->toBe('');
    }
});
