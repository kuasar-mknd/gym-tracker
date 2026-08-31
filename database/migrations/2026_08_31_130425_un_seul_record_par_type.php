<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un seul record par (utilisateur, exercice, type).
 *
 * Rien ne l'imposait : l'index existant n'est pas unique, et les deux chemins
 * d'ecriture font `$pr ??= new PersonalRecord(...)`. Deux ecritures
 * concurrentes sur le meme exercice creent donc deux lignes du meme type.
 *
 * Le doublon est pire qu'inutile : `recompute()` indexe les records par type
 * avec `keyBy()`, qui ne garde que le DERNIER. Le premier n'est jamais mis a
 * jour ni supprime — il annonce indefiniment une valeur que plus rien ne
 * soutient. Constate en production le 31/08 : deux `max_volume_set` sur le meme
 * exercice, dont un pose sur une serie jamais cochee, que trois passages de
 * `--repair` n'ont pas pu corriger.
 *
 * On garde la ligne la plus recente. Sa valeur peut etre fausse elle aussi ;
 * `recompute()` la refera depuis les series, ce que `--repair` declenche.
 */
return new class() extends Migration
{
    public function up(): void
    {
        DB::statement(
            'delete pr from personal_records pr
               join personal_records plus_recent
                 on plus_recent.user_id = pr.user_id
                and plus_recent.exercise_id = pr.exercise_id
                and plus_recent.type = pr.type
                and plus_recent.id > pr.id'
        );

        if (Schema::hasIndex('personal_records', 'personal_records_user_exercise_type_unique')) {
            return;
        }

        Schema::table('personal_records', function (Blueprint $table): void {
            $table->unique(['user_id', 'exercise_id', 'type'], 'personal_records_user_exercise_type_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('personal_records', 'personal_records_user_exercise_type_unique')) {
            return;
        }

        Schema::table('personal_records', function (Blueprint $table): void {
            $table->dropUnique('personal_records_user_exercise_type_unique');
        });
    }
};
