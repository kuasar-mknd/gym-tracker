<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La liste des notifications se trie par date, que rien ne couvrait.
 *
 * `notifications()` est une relation `MorphMany` ordonnee par `created_at` :
 * la page en pagine vingt, mais MySQL triait tout ce que l'utilisateur a jamais
 * recu. Mesure aux compteurs `Handler_read_*` : 204 lectures a 100 lignes,
 * 1 002 a 500. Rien n'archive une notification.
 *
 * `notifications_notifiable_type_notifiable_id_index`, pose par la migration
 * de Laravel, est un prefixe strict de l'index sur `read_at` : il ne sert plus
 * aucune requete que l'autre ne serve, et se paie a chaque envoi.
 */
return new class() extends Migration
{
    public function up(): void
    {
        // Deux appels, pour la meme raison qu'en `index_created_at_des_outils` :
        // un controle pose avant l'ajout ne dit rien de l'etat d'apres.
        if (! Schema::hasIndex('notifications', 'notifications_notifiable_created_at_index')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->index(
                    ['notifiable_type', 'notifiable_id', 'created_at'],
                    'notifications_notifiable_created_at_index'
                );
            });
        }

        if (Schema::hasIndex('notifications', 'notifications_notifiable_type_notifiable_id_index')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->dropIndex('notifications_notifiable_type_notifiable_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->index(
                ['notifiable_type', 'notifiable_id'],
                'notifications_notifiable_type_notifiable_id_index'
            );
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_notifiable_created_at_index');
        });
    }
};
