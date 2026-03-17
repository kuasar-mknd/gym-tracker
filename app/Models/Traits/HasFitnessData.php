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
