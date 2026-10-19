<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\ResolvesOwnerAtRouteBinding;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $workout_template_id
 * @property int $exercise_id
 * @property int $order
 * @property-read \App\Models\WorkoutTemplate $workoutTemplate
 * @property-read \App\Models\Exercise $exercise
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WorkoutTemplateSet> $workoutTemplateSets
 */
class WorkoutTemplateLine extends Model
{
    /** @use HasFactory<\Database\Factories\WorkoutTemplateLineFactory> */
    use HasFactory;

    use ResolvesOwnerAtRouteBinding;

    #[\Override]
    protected $fillable = [
        'workout_template_id',
        'exercise_id',
        'order',
    ];

    #[\Override]
    protected function ownershipPath(): string
    {
        return 'workoutTemplate';
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\WorkoutTemplate, $this>
     */
    public function workoutTemplate(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WorkoutTemplate::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Exercise, $this>
     */
    public function exercise(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * Les séries de cet exercice du modèle, dans leur ordre : `order`, puis
     * `id` pour départager deux rangs égaux.
     *
     * Sans ordre, la base les rendait dans celui qui l'arrangeait, et le
     * lancement du modèle recopiait les séries dans cet ordre-là, que rien ne
     * garantit (#2049) : un échauffement pouvait passer après la série de
     * travail, et le rang d'une série, qui fait sa clé d'idempotence
     * (`WorkoutTemplate::cleDUneSerieLancee()`), changer d'une lecture à
     * l'autre.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\WorkoutTemplateSet, $this>
     */
    public function workoutTemplateSets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkoutTemplateSet::class)->orderBy('order')->orderBy('id');
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }
}
