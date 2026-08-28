<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Combien de seances aujourd'hui, tous comptes confondus ».
 *
 * Les trois index de `workouts` commencent par `user_id` : aucun ne borne une
 * plage de dates GLOBALE. Le tableau de bord d'administration en parcourait
 * donc l'integralite a chaque affichage — `type: index` au plan, 202 lectures
 * pour 200 seances et 1 202 pour 1 200.
 *
 * L'index est etroit et `workouts` s'ecrit une fois par seance, pas par serie :
 * ce qu'il coute a l'ecriture est sans commune mesure avec le balayage qu'il
 * remplace.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('workouts', function (Blueprint $table): void {
            $table->index(['started_at'], 'workouts_started_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('workouts', function (Blueprint $table): void {
            $table->dropIndex('workouts_started_at_index');
        });
    }
};
