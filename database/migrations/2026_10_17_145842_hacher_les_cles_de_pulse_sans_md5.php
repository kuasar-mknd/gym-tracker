<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Pulse\Support\PulseMigration;

/*
 * L'empreinte de clé des trois tables de Pulse se calcule sans `md5()`.
 *
 * Pulse fait calculer `key_hash` par le serveur, colonne générée
 * `unhex(md5(key))` : son stockage n'écrit jamais la colonne sous MySQL, et
 * son unicité (`type`, `key_hash`) porte les upserts. MySQL 9.7 ne fournit
 * plus `md5()` ni `sha1()` en fonctions natives : elles passent dans un
 * composant (`component_classic_hashing`) comme fonctions chargeables, et une
 * colonne générée n'en admet aucune. Le schéma figé ne se chargeait plus sur
 * une base neuve (« contains a disallowed function: `md5` »), et la mise à
 * niveau en place d'une base 8.4 qui garde ces colonnes s'arrête dans le
 * dictionnaire de données : le serveur 9.7 ne démarre pas.
 *
 * L'empreinte devient les seize premiers octets de SHA-256, que MySQL 8.4 et
 * 9.7 calculent nativement : même type `binary(16)`, mêmes index. Elle doit
 * passer sous 8.4, avant la bascule vers 9.7 (`.ai/rules/dependencies.md`).
 *
 * Changer l'expression d'une colonne virtuelle indexée recopie la table :
 * MySQL 8.4 refuse ALGORITHM=INSTANT et INPLACE, et la copie demande
 * LOCK=SHARED. Pendant la copie de chacune des trois tables, les lectures
 * sont servies, les écritures de Pulse attendent, et le disque doit tenir une
 * copie de la table ; la migration tourne au démarrage de `app`, avant
 * Octane, et l'application ne sert qu'une fois les trois copies finies.
 * Mesuré le 2026-10-17 : 8 s pour 252 000 entrées et 240 096 agrégats
 * (environ 76 Mo chacune). Supprimer puis recréer la colonne ôterait l'index
 * unique le temps de l'opération : les upserts de Pulse y dupliqueraient des
 * lignes, et l'index ne se reposerait plus.
 *
 * Sous SQLite, Pulse calcule l'empreinte lui-même dans une colonne ordinaire ;
 * sous PostgreSQL, `md5()` reste une fonction du serveur : rien n'y change.
 * Rejouable : une table dont l'empreinte est déjà celle-ci n'est pas touchée.
 */
return new class() extends PulseMigration
{
    /**
     * L'expression de l'empreinte, écrite comme MySQL la rend
     * (`information_schema`, `SHOW CREATE TABLE`, le schéma figé).
     */
    public const string EMPREINTE = 'unhex(left(sha2(`key`,256),32))';

    /**
     * Les tables de Pulse qui portent `key_hash`.
     *
     * @var list<string>
     */
    public const array TABLES = ['pulse_values', 'pulse_entries', 'pulse_aggregates'];

    public function up(): void
    {
        if (! $this->shouldRun() || ! in_array($this->driver(), ['mariadb', 'mysql'], true)) {
            return;
        }

        foreach (self::TABLES as $nom) {
            if (! $this->doitChanger($nom)) {
                continue;
            }

            Schema::table($nom, function (Blueprint $table): void {
                $table->char('key_hash', 16)->charset('binary')->virtualAs(self::EMPREINTE)->change();
            });
        }
    }

    /**
     * Volontairement vide : `md5()` n'existe plus en fonction native sous
     * MySQL 9.7, et l'empreinte SHA-256 sert Pulse sous 8.4 comme sous 9.7.
     */
    public function down(): void
    {
    }

    /**
     * Vrai quand la table porte `key_hash` sous une autre expression que
     * l'empreinte SHA-256.
     */
    private function doitChanger(string $table): bool
    {
        foreach (Schema::getColumns($table) as $colonne) {
            if ($colonne['name'] === 'key_hash') {
                return ($colonne['generation']['expression'] ?? null) !== self::EMPREINTE;
            }
        }

        return false;
    }
};
