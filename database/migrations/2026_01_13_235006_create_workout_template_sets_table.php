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
        Schema::create('workout_template_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_template_line_id')->constrained()->cascadeOnDelete();
            $table->integer('reps')->nullable();
            $table->decimal('weight', 10, 2)->nullable();
            $table->boolean('is_warmup')->default(false);
            $table->integer('order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_template_sets');
    }
};
