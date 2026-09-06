<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('erreurs_navigateur', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->text('message');
            $table->string('source', 2048)->nullable();
            $table->unsignedInteger('ligne')->nullable();
            $table->unsignedInteger('colonne')->nullable();
            $table->text('pile')->nullable();
            $table->string('url', 2048);
            $table->string('agent', 512)->nullable();
            $table->string('empreinte', 32)->index();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erreurs_navigateur');
    }
};
