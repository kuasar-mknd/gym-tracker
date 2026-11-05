<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Le décalage, en minutes, du début et de la fin d'une séance et d'un jeûne
 * (#2159).
 *
 * Un instant s'écrit à l'heure murale du fuseau de l'application, sans
 * décalage. Pendant l'heure que ce fuseau répète au passage à l'heure d'hiver,
 * les deux occurrences s'écrivent de la même façon, et Eloquent relisait
 * toujours la seconde : une séance commencée dans la première et terminée
 * quarante minutes plus tard était enregistrée pour 0 min, et un jeûne de
 * seize heures en comptait quinze. Le cast `InstantAvecSonDecalage` écrit
 * désormais l'heure murale comme avant, et le décalage à côté, qui désigne
 * l'occurrence à la lecture.
 *
 * L'heure murale reste ce qu'elle était : aucune requête, borne ni expression
 * SQL ne change, et chacune lit toujours cette heure. Nulle : une ligne écrite
 * avant cette migration n'a pas de décalage, et se lit comme avant ; rien ne
 * dit quelle occurrence elle désignait, et aucune ligne n'est donc réécrite.
 * Un `smallint` signé tient tous les décalages d'un fuseau, de -12 h à +14 h.
 *
 * Ajouter une colonne nulle ne recopie pas la table (`ALGORITHM=INSTANT`
 * sous MySQL 8.4 et 9.7) : aucune ligne n'est revérifiée, et la migration ne
 * coupe pas les clés étrangères (`.ai/rules/database.md`). Chaque colonne se
 * juge sur son propre état, pour qu'une migration arrêtée entre deux tables
 * reprenne là où elle en était.
 */
return new class() extends Migration
{
    /**
     * Les colonnes de décalage, par table, chacune derrière l'instant qu'elle
     * accompagne.
     *
     * @var array<string, array<string, string>>
     */
    private const array DECALAGES = [
        'workouts' => ['started_at_offset' => 'started_at', 'ended_at_offset' => 'ended_at'],
        'fasts' => ['start_time_offset' => 'start_time', 'end_time_offset' => 'end_time'],
    ];

    public function up(): void
    {
        foreach (self::DECALAGES as $table => $colonnes) {
            foreach ($colonnes as $decalage => $instant) {
                if (Schema::hasColumn($table, $decalage)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($decalage, $instant): void {
                    $blueprint->smallInteger($decalage)->nullable()->after($instant);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::DECALAGES as $table => $colonnes) {
            foreach (array_keys($colonnes) as $decalage) {
                if (! Schema::hasColumn($table, $decalage)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($decalage): void {
                    $blueprint->dropColumn($decalage);
                });
            }
        }
    }
};
