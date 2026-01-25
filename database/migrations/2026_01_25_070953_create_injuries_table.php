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
        Schema::create('injuries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('body_part');
            $table->string('diagnosis')->nullable();
            $table->enum('severity', ['low', 'medium', 'high']);
            $table->enum('status', ['active', 'recovering', 'healed'])->default('active');
            $table->integer('pain_level')->nullable(); // 1-10
            $table->date('occurred_at');
            $table->date('healed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('injuries');
    }
};
