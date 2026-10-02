<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\PushSubscription;

/*
 * L'adresse d'un abonnement push tient en 1 024 caractères, pas 500.
 *
 * webpush 12.1 a porté la limite à `PushSubscription::ENDPOINT_MAX_LENGTH` et
 * livré la migration qui l'accompagne ; elle n'avait jamais été publiée ici, et
 * la colonne venue du dump restait à 500. Une adresse plus longue — Windows
 * (WNS) en produit — échouait en « Data too long » : un 500 au lieu d'un
 * abonnement.
 *
 * L'ascii n'est pas un détail : l'unicité porte alors sur 1 024 octets, sous les
 * 3 072 d'une clef InnoDB, quand l'utf8mb4 en demanderait 4 096. Une adresse de
 * point de terminaison est une URL, donc de l'ascii.
 *
 * Retirer l'index, changer la colonne, reposer l'index : trois appels séparés,
 * chacun précédé de sa question, pour qu'une reprise après un échec en cours de
 * route retombe sur ses pieds (`MigrationsRejouablesTest`).
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('push_subscriptions', 'push_subscriptions_endpoint_unique')) {
            Schema::table('push_subscriptions', function (Blueprint $table): void {
                $table->dropUnique('push_subscriptions_endpoint_unique');
            });
        }

        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->string('endpoint', PushSubscription::ENDPOINT_MAX_LENGTH)->charset('ascii')->change();
        });

        if (! Schema::hasIndex('push_subscriptions', 'push_subscriptions_endpoint_unique')) {
            Schema::table('push_subscriptions', function (Blueprint $table): void {
                $table->unique('endpoint', 'push_subscriptions_endpoint_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('push_subscriptions', 'push_subscriptions_endpoint_unique')) {
            Schema::table('push_subscriptions', function (Blueprint $table): void {
                $table->dropUnique('push_subscriptions_endpoint_unique');
            });
        }

        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->string('endpoint', 500)->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->change();
        });

        if (! Schema::hasIndex('push_subscriptions', 'push_subscriptions_endpoint_unique')) {
            Schema::table('push_subscriptions', function (Blueprint $table): void {
                $table->unique('endpoint', 'push_subscriptions_endpoint_unique');
            });
        }
    }
};
