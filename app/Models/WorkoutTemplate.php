<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $description
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WorkoutTemplateLine> $workoutTemplateLines
 */
class WorkoutTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\WorkoutTemplateFactory> */
    use HasFactory;

    /**
     * Le plus d'exercices qu'un modèle accepte.
     */
    public const int EXERCICES_MAX = 50;

    /**
     * Le plus de séries qu'un exercice d'un modèle accepte. Chaque série
     * devient une ligne de `workout_template_sets`, puis une série de la
     * séance qui démarre du modèle : cinquante couvrent les méthodes les plus
     * longues (dix fois dix, séries dégressives) et une séance recopiée en
     * modèle, et bornent un modèle à 2 500 séries, insérées en vingt-cinq
     * paquets d'une transaction.
     */
    public const int SERIES_MAX_PAR_EXERCICE = 50;

    /**
     * Les plafonds d'un modèle, que son formulaire reçoit pour ne pas
     * proposer d'ajouter un exercice ou une série que la requête refuserait.
     *
     * @return array{exercices: int, seriesParExercice: int}
     */
    public static function bornes(): array
    {
        return [
            'exercices' => self::EXERCICES_MAX,
            'seriesParExercice' => self::SERIES_MAX_PAR_EXERCICE,
        ];
    }

    #[\Override]
    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\WorkoutTemplateLine, $this>
     */
    public function workoutTemplateLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkoutTemplateLine::class)->orderBy('order')->orderBy('id');
    }
}
