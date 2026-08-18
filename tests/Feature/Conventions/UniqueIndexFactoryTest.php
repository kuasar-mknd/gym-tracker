<?php

declare(strict_types=1);

use App\Models\DailyJournal;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\NotificationPreference;
use App\Models\User;

/*
 * Une contrainte unique composite face a une fabrique qui tire au hasard.
 *
 * La forme est toujours la meme : une cle etrangere fixee par le test, une
 * colonne de valeur tiree au hasard par la fabrique, et une contrainte unique
 * sur le couple. Trois enregistrements pour le meme parent suffisent alors a
 * faire echouer une execution de temps en temps — dans un test sans rapport avec
 * celui qu'on lit, et jamais deux fois au meme endroit.
 *
 * Vu deux fois. `daily_journals (user_id, date)` a fait rougir main sur une PR
 * qui ne touchait que le README (#1475). En generalisant, `habit_logs
 * (habit_id, date)` portait exactement le meme defaut, non declenche.
 *
 * Ce fichier ferme la famille plutot que les cas : le second test relit le
 * schema et exige que chaque contrainte composite du domaine soit couverte
 * ci-dessus. Ajouter un index sans ajouter son cas fait tomber la suite.
 */

/**
 * Les tables du domaine portant une contrainte unique sur plusieurs colonnes.
 *
 * @return list<string>
 */
function tablesAContrainteComposite(): array
{
    $schema = file_get_contents(base_path('database/schema/mysql-schema.sql'));

    if ($schema === false || $schema === '') {
        throw new RuntimeException(
            'Le dump de schema est vide ou illisible. Sans lui, le garde de complétude '
            .'passerait sans rien verifier.'
        );
    }

    // Les tables de bibliotheques tierces ne sont pas de notre ressort : leurs
    // fabriques ne nous appartiennent pas.
    $etrangeres = ['permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions'];

    $tables = [];
    $tableCourante = null;

    foreach (explode("\n", $schema) as $ligne) {
        if (preg_match('/^CREATE TABLE `([a-z_]+)`/', $ligne, $c) === 1) {
            $tableCourante = $c[1];
        }

        // Composite : au moins une virgule entre les colonnes de l'index.
        if ($tableCourante !== null && preg_match('/^\s+UNIQUE KEY `[a-z_]+` \([^)]*,[^)]*\)/', $ligne) === 1) {
            if (! str_starts_with($tableCourante, 'pulse_') && ! in_array($tableCourante, $etrangeres, true)) {
                $tables[$tableCourante] = true;
            }
        }
    }

    if ($tables === []) {
        throw new RuntimeException(
            'Aucune contrainte unique composite lue dans le schema. Le format du dump a-t-il change ? '
            .'Un garde qui ne trouve rien a couvrir passerait sans rien verifier.'
        );
    }

    return array_keys($tables);
}

/**
 * Chaque cas : la table, et de quoi fabriquer trente enregistrements frères.
 *
 * @return array<string, array{0: string, 1: callable(): array<string, mixed>, 2: class-string}>
 */
function casDeContrainteComposite(): array
{
    return [
        'journaux quotidiens' => [
            'daily_journals',
            fn (): array => ['user_id' => User::factory()->create()->id],
            DailyJournal::class,
        ],
        'suivis d’habitude' => [
            'habit_logs',
            fn (): array => ['habit_id' => Habit::factory()->create()->id],
            HabitLog::class,
        ],
        'préférences de notification' => [
            'notification_preferences',
            fn (): array => ['user_id' => User::factory()->create()->id],
            NotificationPreference::class,
        ],
        'succès débloqués' => [
            'user_achievements',
            fn (): array => ['user_id' => User::factory()->create()->id],
            \App\Models\UserAchievement::class,
        ],
    ];
}

/**
 * Trente frères sous le même parent, sans collision.
 *
 * Trente tirages dans un vivier de vingt mille jours collisionnent environ une
 * fois sur cinquante — le garde est donc statistique contre une fabrique
 * fautive, et deterministe pour une fabrique qui distribue une suite. Ce qui le
 * rend utile, ce n'est pas sa sensibilite sur un cas, c'est qu'il tourne a
 * chaque execution, sur toutes les tables concernees.
 */
it('fabrique trente enregistrements frères sans collision', function (string $table, callable $parent, string $modele): void {
    $attributs = $parent();

    $modele::factory()->count(30)->create($attributs);

    expect($modele::query()->where($attributs)->count())->toBe(30);
})->with(casDeContrainteComposite());

/**
 * Et la liste ci-dessus doit couvrir tout ce que le schema contient.
 *
 * Sans ce test, la famille resterait fermee sur les cas connus : le prochain
 * index composite ajoute passerait sous le radar, exactement comme `habit_logs`
 * l'a fait pendant que `daily_journals` etait corrigee.
 */
it('couvre chaque contrainte composite du schéma', function (): void {
    $couvertes = array_map(
        static fn (array $cas): string => $cas[0],
        array_values(casDeContrainteComposite()),
    );

    $manquantes = array_values(array_diff(tablesAContrainteComposite(), $couvertes));

    expect($manquantes)->toBe([], sprintf(
        "Ces tables portent une contrainte unique composite sans cas ci-dessus :\n- %s\n\n"
        .'Ajouter leur cas dans `casDeContrainteComposite()`. Une contrainte composite face a une '
        .'fabrique qui tire au hasard produit un echec rare, ailleurs a chaque fois.',
        implode("\n- ", $manquantes),
    ));
});
