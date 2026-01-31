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
        Schema::create('wilks_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('body_weight', 8, 2);
            $table->decimal('lifted_weight', 8, 2);
            $table->string('gender'); // 'male' or 'female'
            $table->string('unit')->default('kg'); // 'kg' or 'lbs'
            $table->decimal('score', 8, 2);
            $table->timestamps();

            // Index for quick retrieval of user's history
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wilks_scores');
    }
};
