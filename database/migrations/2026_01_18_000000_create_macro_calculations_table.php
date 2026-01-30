<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('macro_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Inputs
            $table->string('gender'); // 'male' or 'female'
            $table->integer('age');
            $table->decimal('height', 8, 2); // cm
            $table->decimal('weight', 8, 2); // kg
            $table->decimal('activity_level', 5, 2); // e.g. 1.2, 1.375...
            $table->string('goal'); // 'cut', 'maintain', 'bulk'

            // Results
            $table->integer('tdee');
            $table->integer('target_calories');
            $table->integer('protein'); // grams
            $table->integer('fat'); // grams
            $table->integer('carbs'); // grams

            $table->timestamps();

            // Index for history
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('macro_calculations');
    }
};
