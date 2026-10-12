<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Les erreurs du navigateur d'un compte partent avec lui (#1938).
 *
 * La clé de `erreurs_navigateur.user_id` vidait la colonne à la suppression
 * du compte (`ON DELETE SET NULL`) : le message, l'URL, la pile et l'agent
 * restaient en base jusqu'à la purge à trente jours, et l'URL peut porter des
 * identifiants. Elle efface désormais la ligne (`ON DELETE CASCADE`), comme
 * toutes les autres clés vers `users`.
 *
 * Les erreurs déjà orphelines (`user_id` nul) partent aussi : ce n'est pas
 * une devinette. Depuis leur introduction (#1766), la route n'accepte que des
 * comptes connectés et le contrôleur pose toujours `user_id` ; la colonne
 * n'est nullable que pour l'ancien `SET NULL`. Une ligne sans compte est donc
 * celle d'un compte supprimé. `down()` ne les rend pas.
 *
 * Retirer la clé puis la reposer : deux appels séparés, chacun précédé de sa
 * question, pour qu'une reprise après un échec en cours de route retombe sur
 * ses pieds. L'index de la colonne reste en place entre les deux, et la clé
 * reposée le reprend. `ErreursNavigateurSuiventLeCompteTest` joue la
 * migration sur MySQL dans chacun de ces états : déjà passée, interrompue
 * entre les deux appels, défaite puis rejouée.
 */
return new class() extends Migration
{
    private const string CLE = 'erreurs_navigateur_user_id_foreign';

    public function up(): void
    {
        $this->reposerLaCle('cascade');

        DB::table('erreurs_navigateur')->whereNull('user_id')->delete();
    }

    public function down(): void
    {
        $this->reposerLaCle('set null');
    }

    /**
     * Repose la clé de `user_id` avec cette règle de suppression, si elle ne
     * l'a pas déjà.
     *
     * @param  'cascade'|'set null'  $regle
     */
    private function reposerLaCle(string $regle): void
    {
        $cle = collect(Schema::getForeignKeys('erreurs_navigateur'))->firstWhere('name', self::CLE);

        if (is_array($cle) && $cle['on_delete'] === $regle) {
            return;
        }

        if (is_array($cle)) {
            Schema::table('erreurs_navigateur', function (Blueprint $table): void {
                $table->dropForeign(self::CLE);
            });
        }

        Schema::table('erreurs_navigateur', function (Blueprint $table) use ($regle): void {
            $table->foreign('user_id', self::CLE)->references('id')->on('users')->onDelete($regle);
        });
    }
};
