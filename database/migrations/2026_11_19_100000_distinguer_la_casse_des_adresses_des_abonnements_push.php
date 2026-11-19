<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\PushSubscription;

/*
 * L'adresse d'un abonnement push se compare octet par octet.
 *
 * `2026_10_02_180000_allonger_l_adresse_des_abonnements_push.php` a passé la
 * colonne en `ascii`, dont la collation par défaut, `ascii_general_ci`, est
 * insensible à la casse : l'index unique et `findByEndpoint()` confondaient une
 * adresse et sa variante en majuscules. L'adresse est un secret de capacité, et
 * une URL distingue la casse de son chemin.
 *
 * Un seul ordre, joué seulement si la collation n'est pas déjà la bonne : une
 * reprise après un arrêt ne lance rien. Le retour en arrière s'arrête (1062)
 * sur deux adresses que seule la casse distingue, la table comme elle était.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if ($this->collation() !== 'ascii_bin') {
            $this->changer('ascii_bin');
        }
    }

    public function down(): void
    {
        if ($this->collation() === 'ascii_bin') {
            $this->changer('ascii_general_ci');
        }
    }

    private function changer(string $collation): void
    {
        Schema::table('push_subscriptions', static function (Blueprint $table) use ($collation): void {
            $table->string('endpoint', PushSubscription::ENDPOINT_MAX_LENGTH)->charset('ascii')->collation($collation)->change();
        });
    }

    private function collation(): ?string
    {
        foreach (Schema::getColumns('push_subscriptions') as $colonne) {
            if ($colonne['name'] === 'endpoint') {
                return is_string($colonne['collation']) ? $colonne['collation'] : null;
            }
        }

        return null;
    }
};
