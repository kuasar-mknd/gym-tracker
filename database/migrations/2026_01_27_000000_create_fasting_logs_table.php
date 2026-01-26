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
        Schema::create('fasting_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('start_time');
            $table->timestamp('end_time')->nullable();
            $table->float('target_duration_hours')->default(16);
            $table->string('type')->default('16:8'); // e.g., '16:8', '18:6', '20:4', 'OMAD', 'Custom'
            $table->string('status')->default('active'); // 'active', 'completed', 'cancelled'
            $table->text('note')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['user_id', 'start_time']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fasting_logs');
    }
};
