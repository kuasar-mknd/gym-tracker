<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La période d'un objectif : `total` (toutes les séances faites, ce que tous
 * les objectifs existants comptent déjà) ou `week` (celles de la semaine du
 * calendrier). Seul l'objectif de fréquence la lit.
 *
 * Une chaîne et non un `enum` MySQL : une valeur qu'un enum relit se compare
 * octet par octet (`.ai/rules/database.md`), et le cast PHP tient déjà la
 * liste. La colonne est obligatoire avec une valeur par défaut : ajoutée ainsi,
 * elle ne recopie pas la table (`ALGORITHM=INSTANT` sous MySQL 8.4 et 9.7),
 * aucune ligne n'est revérifiée et les clés étrangères restent en place. Aucun
 * index : la liste des objectifs d'un compte se lit par `user_id`, et la table
 * ne compte que quelques lignes par compte.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('goals', 'period')) {
            return;
        }

        Schema::table('goals', function (Blueprint $table): void {
            $table->string('period', 16)->default('total')->after('measurement_type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('goals', 'period')) {
            return;
        }

        Schema::table('goals', function (Blueprint $table): void {
            $table->dropColumn('period');
        });
    }
};
