<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Tout ce que cet utilisateur a fait dans les N derniers jours ».
 *
 * `workout_lines_user_exercise_date_index` ne sert pas cette question :
 * `exercise_id` s'intercale entre le proprietaire et la date. Les statistiques
 * qui balaient une fenetre sans viser d'exercice n'avaient donc aucun index, et
 * MySQL attaquait par le catalogue plutot que par les lignes.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('workout_lines', function (Blueprint $table): void {
            $table->index(['user_id', 'workout_started_at'], 'workout_lines_user_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('workout_lines', function (Blueprint $table): void {
            $table->dropIndex('workout_lines_user_date_index');
        });
    }
};
