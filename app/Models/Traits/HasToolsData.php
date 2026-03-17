<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\Fast;
use App\Models\IntervalTimer;
use App\Models\MacroCalculation;
use App\Models\Plate;
use App\Models\SupplementLog;
use App\Models\WaterLog;
use App\Models\WilksScore;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasToolsData
{
    /** @return HasMany<Plate, $this> */
    public function plates(): HasMany
    {
        return $this->hasMany(Plate::class);
    }

    /** @return HasMany<WilksScore, $this> */
    public function wilksScores(): HasMany
    {
        return $this->hasMany(WilksScore::class);
    }

    /** @return HasMany<MacroCalculation, $this> */
    public function macroCalculations(): HasMany
    {
        return $this->hasMany(MacroCalculation::class);
    }

    /** @return HasMany<WaterLog, $this> */
    public function waterLogs(): HasMany
    {
        return $this->hasMany(WaterLog::class);
    }

    /** @return HasMany<IntervalTimer, $this> */
    public function intervalTimers(): HasMany
    {
        return $this->hasMany(IntervalTimer::class);
    }

    /** @return HasMany<Fast, $this> */
    public function fasts(): HasMany
    {
        return $this->hasMany(Fast::class);
    }

    /** @return HasMany<SupplementLog, $this> */
    public function supplementLogs(): HasMany
    {
        return $this->hasMany(SupplementLog::class);
    }
}
