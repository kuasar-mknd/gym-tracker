<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les trois colonnes que Fortify posait sur `users` partent avec lui.
 *
 * La 2FA cote application utilisateur etait activee en configuration
 * (`Features::twoFactorAuthentication`) sans qu'aucune route ne l'expose :
 * `Fortify::ignoreRoutes()` etait appele, et toute l'authentification passe par
 * les controleurs Breeze. Ces colonnes n'ont donc jamais ete ni ecrites ni lues.
 *
 * La 2FA du back-office, elle, fonctionne et n'est pas concernee : elle est
 * fournie par Filament, sur le modele `Admin`, avec ses propres colonnes
 * `app_authentication_secret` et `app_authentication_recovery_codes`.
 *
 * Voir #1347 et #1355.
 */
return new class() extends Migration
{
    public function up(): void
    {
        // Seulement celles qui sont la : une base qui n'a jamais installe
        // Fortify n'en a aucune, et `dropColumn` sur une colonne absente
        // interrompt toute la serie de migrations.
        $presentes = array_values(array_filter(
            ['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'],
            fn (string $colonne): bool => Schema::hasColumn('users', $colonne),
        ));

        if ($presentes === []) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($presentes): void {
            $table->dropColumn($presentes);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }
};
