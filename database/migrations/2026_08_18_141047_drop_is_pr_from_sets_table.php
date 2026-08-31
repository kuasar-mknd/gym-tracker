<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sets.is_pr` n'a jamais servi.
 *
 * Elle n'apparait ni dans `$fillable`, ni dans `casts()`, ni dans le docblock
 * de `App\Models\Set`, et aucune ligne du depot ne la lit ni ne l'ecrit — ni le
 * code applicatif, ni le client, ni les tests, ni les fabriques. Elle vaut donc
 * `0` sur toutes les lignes depuis sa creation, ce qui en fait une colonne qui
 * affirme « cette serie n'est pas un record » a propos de series qui en sont.
 *
 * L'information qu'elle pretendait porter est tenue par `personal_records`, qui
 * pointe la serie detentrice par `set_id`.
 */
return new class() extends Migration
{
    public function up(): void
    {
        /*
         * La colonne n'existe pas partout.
         *
         * Aucune migration ne la cree : elle vient du dump
         * `database/schema/mysql-schema.sql`, que seule une base construite a
         * neuf charge. Une base migree pas a pas depuis avant le dump ne l'a
         * jamais eue — et cette migration l'a appris en cassant un deploiement
         * de production le 31/08, laissant les quinze suivantes non appliquees.
         */
        if (! Schema::hasColumn('sets', 'is_pr')) {
            return;
        }

        Schema::table('sets', function (Blueprint $table): void {
            $table->dropColumn('is_pr');
        });
    }

    public function down(): void
    {
        Schema::table('sets', function (Blueprint $table): void {
            $table->boolean('is_pr')->default(false)->after('is_completed');
        });
    }
};
