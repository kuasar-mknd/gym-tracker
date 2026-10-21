<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La clé d'idempotence d'une séance (#2049).
 *
 * Une séance démarrée sans réseau naît sur l'appareil : la file hors ligne
 * l'envoie plus tard, sous une clé que l'appareil a tirée (un UUID, en-tête
 * `Idempotency-Key`), et la rejoue tant qu'aucune réponse ne lui est
 * parvenue. Un démarrage que le serveur a écrit et dont la réponse s'est
 * perdue ressemble trait pour trait à un démarrage jamais arrivé : sans la
 * clé, le rejeu ouvrait une seconde séance, et les séries saisies hors ligne
 * se rattachaient à l'une ou à l'autre. La clé fait du rejeu la même séance,
 * comme pour les lignes et les séries (migration 2026_08_04_123618).
 *
 * Unique PAR COMPTE, et non pour toute la table : la clé arrive dans un
 * en-tête que le client écrit, et `DemarrerUneSeanceAction` ne la cherche
 * que parmi les séances du compte, pour qu'un rejeu de la clé d'un autre ne
 * rende jamais sa séance. Un index global contredirait cette recherche : elle
 * ne trouverait rien, l'insertion heurterait la clé d'un inconnu, et un
 * démarrage honnête échouerait ; il offrirait aussi à quiconque de brûler des
 * clés pour tous. L'index est la seule garde qui tienne quand deux rejeux
 * lisent « absente » au même instant (le verrou du compte manqué) : l'action
 * rattrape sa violation et rend la gagnante.
 *
 * Nulle : les séances d'avant, et celles que le site démarre sans clé
 * (formulaire, panneau, factories), n'en ont pas, et un index unique admet
 * autant de NULL qu'il en faut.
 *
 * La clé d'abord, le compte ensuite. MySQL 9.7.2 ne vérifie plus une clé
 * étrangère quand l'index qui la porte a une colonne nulle dans la ligne
 * écrite, même hors de la clé : sous `(user_id, idempotency_key)`, qu'InnoDB
 * prend pour `workouts_user_id_foreign` parce qu'il est unique, une séance
 * sans clé s'écrivait sous un compte qui n'existe pas (relu le 2026-10-21,
 * `LesErreursSqlNeRecopientPasLesValeursTest` sur 9.7.2). Rangé
 * `(idempotency_key, user_id)`, l'index ne commence plus par le compte : la
 * clé étrangère garde `(user_id, started_at)`, sans colonne nulle, et la
 * recherche d'une clé parmi les séances d'un compte le lit toujours en entier
 * (`.ai/rules/database.md`).
 *
 * En `ascii_bin` : la clé n'admet que l'ASCII imprimable
 * (`CreateSetAction::idempotencyKey()`), et une égalité d'identité ne se
 * confie pas à une collation (`.ai/rules/actions.md`) : sous
 * `utf8mb4_unicode_ci`, deux clés qui ne diffèrent que par la casse, ou par
 * une espace finale, seraient une seule séance.
 *
 * Aucune contrainte « une seule séance en cours par compte » : il faudrait un
 * index fonctionnel sur `ended_at`, donc une colonne générée cachée, que la
 * restauration des sauvegardes n'admet que pour Pulse
 * (`.ai/rules/database.md`), et d'anciennes séances ouvertes en double
 * (#1958) feraient échouer la migration. Le verrou du compte tient cette
 * règle au démarrage (`DemarrerUneSeanceAction`).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('workouts', function (Blueprint $table): void {
            if (! Schema::hasColumn('workouts', 'idempotency_key')) {
                $table->string('idempotency_key', 64)->charset('ascii')->collation('ascii_bin')->nullable();
            }

            if (! Schema::hasIndex('workouts', 'workouts_idempotency_key_user_id_unique')) {
                $table->unique(['idempotency_key', 'user_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('workouts', function (Blueprint $table): void {
            if (Schema::hasIndex('workouts', 'workouts_idempotency_key_user_id_unique')) {
                $table->dropUnique(['idempotency_key', 'user_id']);
            }

            if (Schema::hasColumn('workouts', 'idempotency_key')) {
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
