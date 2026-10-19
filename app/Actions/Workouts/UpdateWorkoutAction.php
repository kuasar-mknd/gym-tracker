<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Workout;
use App\Services\Stats\StatsCacheManager;
use Illuminate\Support\Arr;

final class UpdateWorkoutAction
{
    public function __construct(protected StatsCacheManager $statsCache)
    {
    }

    /**
     * Update the given workout with new data.
     *
     * @param  array{started_at?: string|null, name?: string|null, notes?: string|null, is_finished?: bool}  $data
     */
    public function execute(Workout $workout, array $data): Workout
    {
        // Meme raison que dans les quatre actions de creation : le stub de
        // `Arr::only` rend un `array` nu, la forme du parametre garantit des
        // cles textuelles, et `fill()` les exige.
        /** @var array<string, mixed> $attributs */
        $attributs = Arr::only($data, ['started_at', 'name', 'notes']);

        $workout->fill($attributs);

        // Check what changed to determine cache invalidation strategy
        $needsFullClear = $workout->isDirty(['started_at', 'ended_at']);
        $needsMetaClear = $workout->isDirty(['name']);

        // Maintenant, sans passer le début : un début que les réglages ont
        // déplacé dans le futur donnait une fin d'avant le début (#2049).
        if ($data['is_finished'] ?? false) {
            $workout->ended_at = $workout->finBornee(null, now());
            $needsFullClear = true;
        }

        $workout->save();

        if ($needsFullClear) {
            // started_at/ended_at change affects volume, duration and meta (histories)
            $this->statsCache->clearWorkoutRelatedStats($workout->user);
        } elseif ($needsMetaClear) {
            $this->statsCache->clearWorkoutMetadataStats($workout->user);
        }

        return $workout;
    }
}
