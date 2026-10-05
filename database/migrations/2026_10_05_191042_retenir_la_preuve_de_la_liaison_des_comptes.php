<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Quand la liaison du compte à son fournisseur de connexion (`provider`,
 * `provider_id`) a été prouvée sur l'adresse exacte du compte.
 *
 * `ResolveSocialUserAction` la pose quand l'identité crée le compte, quand
 * elle s'y rattache par l'adresse exacte et garantie, ou quand elle revient
 * avec cette adresse. Une liaison prouvée se reconnaît ensuite à l'identité
 * seule, même si l'adresse change chez le fournisseur ; une liaison sans
 * preuve, comme toutes celles d'avant cette colonne, ne s'ouvre que pour
 * l'adresse exacte du compte. `OublieLaPreuveDeSaLiaison` l'efface quand
 * l'adresse du compte change. Aucune liaison existante n'est tenue pour
 * prouvée : rien ne dit sur quelle adresse elle a été posée.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'liaison_prouvee_le')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('liaison_prouvee_le')->nullable()->after('provider_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'liaison_prouvee_le')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('liaison_prouvee_le');
        });
    }
};
