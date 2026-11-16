<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `(user_id, exercise_id, type)` des records n'a plus qu'un index : l'unique
 * de `2026_08_31_130425_un_seul_record_par_type.php`, qui sert chaque lecture
 * que servait l'index simple de mêmes colonnes (#2325). L'index simple, un
 * doublon exact, coûtait une entrée de plus à chaque écriture d'un record.
 *
 * Il ne part que si un unique de mêmes colonnes existe, trouvé par ses
 * colonnes et non par son nom. La clé étrangère du compte garde de quoi être
 * portée : l'unique commence par `user_id`, sans colonne nullable derrière
 * (`.ai/rules/database.md`). `down()` recrée l'index simple, sans toucher à
 * l'unique ; chaque ordre juge l'état de la table.
 */
return new class() extends Migration
{
    private const array COLONNES = ['user_id', 'exercise_id', 'type'];

    public function up(): void
    {
        if (! Schema::hasIndex('personal_records', self::COLONNES, 'unique')) {
            return;
        }

        foreach (Schema::getIndexes('personal_records') as $index) {
            if ($index['columns'] !== self::COLONNES || $index['primary'] || $index['unique']) {
                continue;
            }

            $nom = $index['name'];

            Schema::table('personal_records', function (Blueprint $blueprint) use ($nom): void {
                $blueprint->dropIndex($nom);
            });
        }
    }

    public function down(): void
    {
        if ($this->existe(unique: false)) {
            return;
        }

        Schema::table('personal_records', function (Blueprint $blueprint): void {
            $blueprint->index(self::COLONNES, 'personal_records_user_id_exercise_id_type_index');
        });
    }

    private function existe(bool $unique): bool
    {
        foreach (Schema::getIndexes('personal_records') as $index) {
            if ($index['columns'] === self::COLONNES && ! $index['primary'] && $index['unique'] === $unique) {
                return true;
            }
        }

        return false;
    }
};
