<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La dernière adresse vérifiée d'un compte, quand elle a été remplacée et que
 * le compte n'a pas été vérifié depuis.
 *
 * Un changement d'adresse laisse le compte non vérifié, et seule une adresse
 * vérifiée est prévenue d'un changement : sans cette mémoire, le changement
 * suivant ne prévenait personne. `SurveilleSonAdresse` la pose quand une
 * adresse vérifiée est remplacée, prévient cette adresse de chaque changement
 * tant que le compte reste non vérifié, et la vide dès qu'il l'est de nouveau.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'ancienne_adresse_verifiee')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('ancienne_adresse_verifiee')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'ancienne_adresse_verifiee')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ancienne_adresse_verifiee');
        });
    }
};
