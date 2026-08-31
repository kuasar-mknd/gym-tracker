<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `latest()` sur trois tables qui n'indexaient que leur clef etrangere.
 *
 * La borne posee sur ces lectures ne bornait que ce qui remonte : MySQL triait
 * quand meme tout ce que l'utilisateur possede avant d'en prendre cent. Mesure
 * aux compteurs `Handler_read_*` a 50 puis 250 lignes : objectifs 53 -> 251,
 * minuteurs 53 -> 251, gabarits 205 -> 355.
 *
 * L'index a colonne unique disparait : le nouveau la porte en tete, ce qui
 * suffit a la contrainte, et une seconde copie se paie a chaque ecriture.
 */
return new class() extends Migration
{
    /** @var array<string, string> */
    private const array TABLES = [
        'goals' => 'goals_user_id_foreign',
        'workout_templates' => 'workout_templates_user_id_foreign',
        'interval_timers' => 'interval_timers_user_id_foreign',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $ancien) {
            /*
             * L'ajout D'ABORD, la suppression ENSUITE, dans deux appels.
             *
             * Dans un seul `Schema::table()`, les deux `hasIndex` sont evalues
             * avant qu'aucun ordre ne parte. Or l'ajout de `(user_id,
             * created_at)` fait disparaitre `<table>_user_id_foreign` de
             * lui-meme : InnoDB ne garde l'index qu'il cree pour une contrainte
             * que tant qu'aucun autre ne peut la servir. Le controle voyait
             * donc un index que l'ajout venait de supprimer, et le `DROP`
             * echouait en 1091 — ce qui a interrompu un deploiement de
             * production le 31/08.
             *
             * Separes, le second controle lit l'etat d'apres l'ajout.
             */
            if (! Schema::hasIndex($table, $table.'_user_id_created_at_index')) {
                Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                    $blueprint->index(['user_id', 'created_at'], $table.'_user_id_created_at_index');
                });
            }

            if (Schema::hasIndex($table, $ancien)) {
                Schema::table($table, function (Blueprint $blueprint) use ($ancien): void {
                    $blueprint->dropIndex($ancien);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $ancien) {
            Schema::table($table, function (Blueprint $blueprint) use ($ancien): void {
                $blueprint->index(['user_id'], $ancien);
            });

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex($table.'_user_id_created_at_index');
            });
        }
    }
};
