<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('workouts', function (Blueprint $table): void {
            if (Schema::hasIndex('workouts', 'workouts_user_id_volume_index')) {
                return;
            }

            // Couvre la somme qui derive `users.total_volume` : lue dans l'index,
            // sans acces aux lignes.
            $table->index(['user_id', 'workout_volume'], 'workouts_user_id_volume_index');
        });

        Schema::table('personal_records', function (Blueprint $table): void {
            if (Schema::hasIndex('personal_records', 'personal_records_user_type_value_index')) {
                return;
            }

            // `(user_id, exercise_id, type)` ne sert pas `WHERE user_id AND type` :
            // la colonne du milieu est sautee, le prefixe utilisable s'arrete a
            // `user_id`, et `MAX(value)` imposait un acces par ligne.
            $table->index(['user_id', 'type', 'value'], 'personal_records_user_type_value_index');
        });
    }

    public function down(): void
    {
        Schema::table('workouts', function (Blueprint $table): void {
            $table->dropIndex('workouts_user_id_volume_index');
        });

        Schema::table('personal_records', function (Blueprint $table): void {
            $table->dropIndex('personal_records_user_type_value_index');
        });
    }
};
