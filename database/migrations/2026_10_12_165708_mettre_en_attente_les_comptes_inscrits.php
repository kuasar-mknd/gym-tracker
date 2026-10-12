<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Depuis quand un compte attend que le lien envoyé à son adresse soit suivi.
 *
 * L'inscription crée désormais un compte en attente (`CreateUserAction`), qui
 * ne se connecte pas tant que son adresse n'est pas prouvée : lien suivi puis
 * connexion, réinitialisation du mot de passe, ou reprise par un fournisseur
 * qui garantit l'adresse. Toute preuve efface la colonne
 * (`AttendLaPreuveDeSonAdresse`), et un compte resté en attente au-delà de
 * `User::JOURS_AVANT_LA_PURGE` jours est effacé chaque nuit (`model:prune`,
 * l'index sert cette recherche).
 *
 * Aucun compte existant n'est mis en attente : la colonne naît vide, et un
 * compte créé avant elle, vérifié ou non, se connecte comme avant.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'en_attente_depuis')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('en_attente_depuis')->nullable()->after('email_verified_at');
            });
        }

        if (Schema::hasIndex('users', ['en_attente_depuis'])) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->index('en_attente_depuis');
        });
    }

    public function down(): void
    {
        if (Schema::hasIndex('users', ['en_attente_depuis'])) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(['en_attente_depuis']);
            });
        }

        if (! Schema::hasColumn('users', 'en_attente_depuis')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('en_attente_depuis');
        });
    }
};
