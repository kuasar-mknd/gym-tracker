<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les jetons de l'accès de diagnostic (#2032).
 *
 * Seule l'empreinte (sha256) du secret est gardée. Aucune colonne
 * polymorphe : les deux administrateurs sont des clefs étrangères qui
 * s'annulent à la suppression de l'administrateur, car le jeton survit
 * 90 jours à sa fin (`Prunable`) avec sa raison de révocation.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('jetons_de_diagnostic', function (Blueprint $table): void {
            $table->id();
            $table->string('identifiant', 12)->unique();
            $table->char('empreinte', 64);
            $table->string('nom', 80);
            $table->json('portees');
            $table->foreignId('cree_par_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('expire_le');
            $table->timestamp('revoque_le')->nullable();
            $table->foreignId('revoque_par_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('raison_de_revocation', 40)->nullable();
            $table->timestamp('derniere_utilisation_le')->nullable();
            $table->string('derniere_adresse', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jetons_de_diagnostic');
    }
};
