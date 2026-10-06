<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarmupPreference extends Model
{
    /** @use HasFactory<\Database\Factories\WarmupPreferenceFactory> */
    use HasFactory;

    /**
     * La barre la plus lourde, en kilogrammes : un chariot de presse, la plus
     * lourde « barre » qu'on échauffe, pèse moins. Sous decimal(8,2).
     */
    public const int POIDS_DE_BARRE_MAX_KG = 200;

    /**
     * Le plus gros arrondi, en kilogrammes : la page propose 0,5 à 5 ; au-delà
     * de dix, l'arrondi effacerait les paliers d'une montée en charge.
     */
    public const int ARRONDI_MAX_KG = 10;

    /**
     * Le plus de paliers d'une montée en charge, gardés en JSON.
     */
    public const int PALIERS_MAX = 20;

    /**
     * Le plus de répétitions d'un palier d'échauffement.
     */
    public const int REPETITIONS_MAX_PAR_PALIER = 50;

    #[\Override]
    protected $fillable = [
        'user_id',
        'bar_weight',
        'rounding_increment',
        'steps',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'bar_weight' => 'float',
            'rounding_increment' => 'float',
            'steps' => 'array',
        ];
    }
}
