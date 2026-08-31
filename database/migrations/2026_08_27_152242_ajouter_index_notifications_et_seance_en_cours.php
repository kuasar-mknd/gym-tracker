<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deux index pour les deux questions posées à chaque requête web.
 *
 * `HandleInertiaRequests::share()` tourne avant `$next($request)`, donc sur
 * toute requête du groupe `web` : il compte les notifications non lues, cherche
 * le dernier trophée, et demande si une séance est ouverte. Aucune des trois
 * n'était servie par un index au-delà du filtre par utilisateur.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            if (Schema::hasIndex('notifications', 'notifications_notifiable_read_at_index')) {
                return;
            }

            // `read_at IS NULL` n'était couvert par rien : MySQL remontait chaque
            // notification jamais reçue, lues comprises, pour la tester.
            $table->index(
                ['notifiable_type', 'notifiable_id', 'read_at'],
                'notifications_notifiable_read_at_index'
            );
        });

        Schema::table('workouts', function (Blueprint $table): void {
            if (Schema::hasIndex('workouts', 'workouts_user_id_ended_at_index')) {
                return;
            }

            // Le pire cas est le cas normal : quand aucune séance n'est ouverte,
            // rien n'arrête le parcours et le `LIMIT 1` ne coupe jamais.
            $table->index(
                ['user_id', 'ended_at', 'started_at'],
                'workouts_user_id_ended_at_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_notifiable_read_at_index');
        });

        Schema::table('workouts', function (Blueprint $table): void {
            $table->dropIndex('workouts_user_id_ended_at_index');
        });
    }
};
