<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Une exception levée par une requête d'un compte connecté part avec lui
 * (#1938).
 *
 * `filament_exceptions_table` n'avait aucune colonne vers le compte, et une
 * partie de ses lignes le désignait pourtant : l'en-tête
 * `X-Compte-De-L-Ecriture` des écritures rejouées porte son identifiant, les
 * paramètres de route recopient le modèle lié (son `user_id` et ses champs),
 * et le message d'une erreur SQL recopie les valeurs envoyées. Ces lignes
 * restaient jusqu'à la purge à trente jours. `user_id` reçoit désormais le
 * compte de la requête (`ExceptionEnregistree`), et sa clé efface la ligne
 * avec lui (`ON DELETE CASCADE`). Les lignes déjà écrites restent sans lien :
 * les rapprocher d'un compte par leurs en-têtes serait deviner, elles partent
 * à trente jours.
 *
 * La colonne, puis la clé : deux appels séparés, chacun précédé de sa
 * question, pour qu'une reprise après un échec entre les deux retombe sur ses
 * pieds. `ExceptionsSuiventLeCompteTest` joue la migration sur MySQL dans
 * chacun de ces états : déjà passée, interrompue entre les deux appels,
 * défaite puis rejouée.
 */
return new class() extends Migration
{
    private const string TABLE = 'filament_exceptions_table';

    private const string CLE = 'filament_exceptions_table_user_id_foreign';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'user_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            });
        }

        if (! $this->aSaCle()) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('user_id', self::CLE)->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->aSaCle()) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropForeign(self::CLE);
            });
        }

        if (Schema::hasColumn(self::TABLE, 'user_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('user_id');
            });
        }
    }

    /**
     * La clé de `user_id` est-elle posée ?
     */
    private function aSaCle(): bool
    {
        return collect(Schema::getForeignKeys(self::TABLE))->contains('name', self::CLE);
    }
};
