<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property int $user_id
 * @property string $part
 * @property string $value
 * @property string $unit
 * @property \Illuminate\Support\Carbon $measured_at
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 */
class BodyPartMeasurement extends BaseMeasurement
{
    /** @use HasFactory<\Database\Factories\BodyPartMeasurementFactory> */
    use HasFactory;

    /**
     * Les parties du corps que le produit propose.
     *
     * `part` reste du texte libre — on n'ecrit pas dans le dos de qui saisit
     * « Mollet gauche ». Mais les objectifs et le formulaire de saisie doivent
     * s'accorder sur les MEMES noms, sans quoi un objectif sur le tour de
     * taille ne trouverait jamais les mesures saisies.
     *
     * @var list<string>
     */
    public const array COMMON_PARTS = [
        'Neck',
        'Shoulders',
        'Chest',
        'Biceps L',
        'Biceps R',
        'Forearm L',
        'Forearm R',
        'Waist',
        'Hips',
        'Thigh L',
        'Thigh R',
        'Calf L',
        'Calf R',
    ];

    /**
     * Le nom affiché de chaque partie proposée.
     *
     * La clef reste anglaise en base : les mesures déjà saisies et les
     * objectifs qui les suivent s'y rapportent (#1657). Ce qui manquait, c'est
     * le nom qu'on lit à l'écran, où « Waist » ou « Thigh L » ne disaient rien
     * à un francophone (#1974). Une partie saisie librement s'affiche telle
     * quelle.
     *
     * @var array<string, string>
     */
    public const array LIBELLES = [
        'Neck' => 'Cou',
        'Shoulders' => 'Épaules',
        'Chest' => 'Poitrine',
        'Biceps L' => 'Biceps gauche',
        'Biceps R' => 'Biceps droit',
        'Forearm L' => 'Avant-bras gauche',
        'Forearm R' => 'Avant-bras droit',
        'Waist' => 'Taille',
        'Hips' => 'Hanches',
        'Thigh L' => 'Cuisse gauche',
        'Thigh R' => 'Cuisse droite',
        'Calf L' => 'Mollet gauche',
        'Calf R' => 'Mollet droit',
    ];

    /**
     * Le nom à afficher pour une partie : le français d'une partie proposée,
     * le nom saisi pour toute autre.
     *
     * La comparaison ignore la casse, comme la collation de la colonne, qui
     * range « waist » avec « Waist ».
     */
    public static function libelle(string $partie): string
    {
        foreach (self::LIBELLES as $clef => $libelle) {
            if (strcasecmp($clef, $partie) === 0) {
                return $libelle;
            }
        }

        return $partie;
    }

    /**
     * Le nom à afficher pour une partie, parmi celles que le compte mesure.
     *
     * Avant les noms français, un francophone a pu saisir « Taille » à la
     * main. S'il mesure aussi la partie proposée, `Waist` s'affiche « Taille »
     * elle aussi : la partie saisie à la main porte alors la mention « (saisie
     * libre) », sur sa carte comme sur sa page, pour que les deux historiques
     * se distinguent (#1974).
     *
     * @param  iterable<string>  $partiesDuCompte
     */
    public static function libelleParmi(string $partie, iterable $partiesDuCompte): string
    {
        $clef = self::clefDePartie($partie);

        if ($clef === $partie) {
            return self::libelle($partie);
        }

        foreach ($partiesDuCompte as $autre) {
            if (strcasecmp($autre, $clef) === 0) {
                return $partie.' (saisie libre)';
            }
        }

        return $partie;
    }

    /**
     * La clef d'une partie proposée dont on a saisi le nom français
     * (« Taille », « mollet gauche »), ou la saisie telle quelle.
     *
     * Le formulaire affiche les noms français : sans ce retour à la clef, la
     * mesure saisie sous « Taille » serait rangée à part de celles de
     * « Waist », et l'objectif sur le tour de taille ne la verrait jamais.
     */
    public static function clefDePartie(string $saisie): string
    {
        $cherche = mb_strtolower(trim($saisie));

        foreach (self::LIBELLES as $clef => $libelle) {
            if (mb_strtolower($libelle) === $cherche) {
                return $clef;
            }
        }

        return $saisie;
    }

    #[\Override]
    protected $fillable = [
        'user_id',
        'part',
        'value',
        'unit',
        'measured_at',
        'notes',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'measured_at' => 'date:Y-m-d',
            'value' => 'decimal:2',
        ];
    }
}
