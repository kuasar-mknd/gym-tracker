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
        Schema::create('warmup_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('bar_weight', 8, 2)->default(20.0);
            $table->decimal('rounding_increment', 8, 2)->default(2.5);
            $table->json('steps')->nullable();
            $table->timestamps();

            // Explicit index for foreign key
            if (! Schema::hasIndex('warmup_preferences', ['user_id'])) {
                $table->index('user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warmup_preferences');
    }
};
