<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une preference d'echauffement par compte, garantie par le schema.
 *
 * `User::warmupPreference()` est un `hasOne`, mais l'API faisait un `create()`
 * nu : chaque POST empilait une ligne que la relation ne lisait jamais. Le
 * chemin web, lui, faisait deja `updateOrCreate`.
 *
 * Les doublons existants sont supprimes en gardant le plus recent — c'est celui
 * que le `hasOne` aurait rendu, donc celui que l'utilisateur voyait.
 */
return new class() extends Migration
{
    public function up(): void
    {
        DB::statement(
            'delete p from warmup_preferences p
               join warmup_preferences plus_recente
                 on plus_recente.user_id = p.user_id
                and plus_recente.id > p.id'
        );

        Schema::table('warmup_preferences', function (Blueprint $table): void {
            $table->unique('user_id', 'warmup_preferences_user_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('warmup_preferences', function (Blueprint $table): void {
            $table->dropUnique('warmup_preferences_user_id_unique');
        });
    }
};
