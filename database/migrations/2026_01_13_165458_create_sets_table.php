<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_line_id')->constrained('workout_lines')->cascadeOnDelete();

            // Données Muscu
            $table->decimal('weight', 8, 2)->nullable();
            $table->integer('reps')->nullable();

            // Données Cardio / Temps
            $table->integer('duration_seconds')->nullable();
            $table->decimal('distance_km', 8, 3)->nullable();

            $table->boolean('is_warmup')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sets');
    }
};
