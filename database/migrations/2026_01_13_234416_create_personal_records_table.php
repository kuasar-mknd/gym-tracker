<?php

declare(strict_types=1);

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
        Schema::create('personal_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // max_weight, max_1rm, max_volume_set
            $table->decimal('value', 10, 2);
            $table->decimal('secondary_value', 10, 2)->nullable(); // e.g., reps for max_weight
            $table->foreignId('workout_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('set_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('achieved_at');
            $table->timestamps();

            $table->index(['user_id', 'exercise_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_records');
    }
};
