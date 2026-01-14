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
        Schema::create('daily_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->text('content')->nullable();
            $table->integer('mood_score')->nullable(); // 1-5
            $table->integer('sleep_quality')->nullable(); // 1-5
            $table->integer('stress_level')->nullable(); // 1-10
            $table->integer('energy_level')->nullable(); // 1-10
            $table->integer('motivation_level')->nullable(); // 1-10
            $table->integer('nutrition_score')->nullable(); // 1-5
            $table->integer('training_intensity')->nullable(); // 1-10
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_journals');
    }
};
