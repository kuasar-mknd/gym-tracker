<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\Achievement;
use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
use App\Models\DailyJournal;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\PersonalRecord;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

trait HasFitnessData
{
    /** @return HasMany<Workout, $this> */
    public function workouts(): HasMany
    {
        return $this->hasMany(Workout::class);
    }

    /**
     * Les séances FAITES : terminées, avec au moins une série cochée (#2110).
     *
     * Ce que comptent les succès de séances (`AchievementService`, type
     * `count`) et les objectifs de fréquence (`GoalService`), par cette seule
     * relation : les deux comptaient toutes les séances du compte, et
     * `Workout::saved` relance leurs recalculs dès la création. Démarrer une
     * séance débloquait donc « Première Séance », notification comprise,
     * avant toute série ; trois séances démarrées puis terminées sans rien
     * soulever, « Guerrier de la Semaine ».
     *
     * Une série cochée, parce que c'est ce que l'application tient pour fait
     * partout ailleurs : l'interface crée chaque série décochée, et ni le
     * volume (`Workout::recalculerLeVolume()`, #1499) ni les records ne
     * comptent une série que personne n'a cochée. Une séance lancée depuis un
     * modèle porte ses séries prévues, décochées : terminée sans en cocher
     * une, elle ne compte pas. Terminée, parce qu'une séance en cours peut
     * encore être abandonnée, et que les libellés des succès parlent de
     * séances complétées.
     *
     * `withCount('seancesFaites')` rend le même compte sous
     * `seances_faites_count`, l'attribut que `GoalService` retient.
     *
     * Mesuré sur 8.4.11 et 9.7.2 (deltas de `Handler_read%`, 100 puis 400
     * séances terminées de quatre lignes de quatre séries, trois comptes
     * voisins de 400 séances) : une semi-jointure qui parcourt les séances du
     * compte par `(user_id, started_at)` et s'arrête, pour chacune, à sa
     * première série cochée. Quand c'est la première série de la première
     * ligne, 2N + 1 lectures d'index et N lectures suivantes ; au pire, une
     * séance terminée sans aucune série cochée lit toutes ses lignes et
     * toutes ses séries (5N + 1 et 21N). Le compte de toutes les séances en
     * faisait 1 et N.
     *
     * @return HasMany<Workout, $this>
     */
    public function seancesFaites(): HasMany
    {
        return $this->hasMany(Workout::class)
            ->whereNotNull('workouts.ended_at')
            ->whereExists(fn (\Illuminate\Database\Query\Builder $seriesCochees): \Illuminate\Database\Query\Builder => $seriesCochees
                ->selectRaw('1')
                ->from('workout_lines')
                ->join('sets', 'sets.workout_line_id', '=', 'workout_lines.id')
                ->whereColumn('workout_lines.workout_id', 'workouts.id')
                ->where('sets.is_completed', true));
    }

    /** @return HasManyThrough<WorkoutLine, Workout, $this> */
    public function workoutLines(): HasManyThrough
    {
        return $this->hasManyThrough(WorkoutLine::class, Workout::class);
    }

    /** @return HasMany<WorkoutTemplate, $this> */
    public function workoutTemplates(): HasMany
    {
        return $this->hasMany(WorkoutTemplate::class);
    }

    /** @return HasMany<BodyMeasurement, $this> */
    public function bodyMeasurements(): HasMany
    {
        return $this->hasMany(BodyMeasurement::class);
    }

    /** @return HasMany<BodyPartMeasurement, $this> */
    public function bodyPartMeasurements(): HasMany
    {
        return $this->hasMany(BodyPartMeasurement::class);
    }

    /** @return HasMany<PersonalRecord, $this> */
    public function personalRecords(): HasMany
    {
        return $this->hasMany(PersonalRecord::class);
    }

    /** @return HasMany<DailyJournal, $this> */
    public function dailyJournals(): HasMany
    {
        return $this->hasMany(DailyJournal::class);
    }

    /** @return HasMany<Goal, $this> */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    /** @return HasMany<Habit, $this> */
    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class);
    }

    /** @return BelongsToMany<Achievement, $this, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'> */
    public function achievements(): BelongsToMany
    {
        return $this->belongsToMany(Achievement::class, 'user_achievements')
            ->withPivot('achieved_at')
            ->withTimestamps();
    }
}
