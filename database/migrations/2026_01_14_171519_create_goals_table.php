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
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->enum('type', ['weight', 'frequency', 'volume', 'measurement']);
            $table->double('target_value');
            $table->double('current_value')->default(0);
            $table->double('start_value')->default(0);
            $table->foreignId('exercise_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('measurement_type')->nullable(); // e.g., 'weight', 'waist', 'body_fat'
            $table->date('deadline')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
