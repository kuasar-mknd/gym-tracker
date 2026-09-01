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
