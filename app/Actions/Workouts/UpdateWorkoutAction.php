<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Workout;
use App\Services\Stats\StatsCacheManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

final class UpdateWorkoutAction
{
    public function __construct(protected StatsCacheManager $statsCache)
    {
    }

    /**
     * Update the given workout with new data.
     *
     * La fin par la page (`is_finished`, que gardent les pages d'avant #2049
     * encore ouvertes) passe sous le verrou des séances du compte, comme celle
     * de la file (`TerminerLaSeanceAction`) : la séance relue sous lui, une
     * fin arrivée entre-temps est gardée, jamais remplacée par maintenant.
     *
     * @param  array{started_at?: string|null, name?: string|null, notes?: string|null, is_finished?: bool}  $data
     * @param  CarbonInterface|null  $maintenant  l'instant de la requête, celui qui a jugé le début
     *                                            (`UpdateWorkoutRequest::maintenant()`) : la fin le reprend
     *                                            (`.ai/rules/horloge.md`) ; lu ici sans lui
     *
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException Si le verrou du compte reste pris plus de `DemarrerUneSeanceAction::ATTENTE_DU_VERROU` secondes.
     */
    public function execute(Workout $workout, array $data, ?CarbonInterface $maintenant = null): Workout
    {
        $maintenant ??= now();

        if (! ($data['is_finished'] ?? false)) {
            return $this->enregistrer($workout, $data, $maintenant, false);
        }

        /** @var Workout $close */
        $close = DemarrerUneSeanceAction::verrouDuCompte($workout->user_id)
            ->block(DemarrerUneSeanceAction::ATTENTE_DU_VERROU, function () use ($workout, $data, $maintenant): Workout {
                // Relue sous le verrou : une autre fin a pu passer depuis la liaison de la séance.
                $workout->refresh();

                return $this->enregistrer($workout, $data, $maintenant, true);
            });

        return $close;
    }

    /**
     * @param  array{started_at?: string|null, name?: string|null, notes?: string|null, is_finished?: bool}  $data
     */
    private function enregistrer(Workout $workout, array $data, CarbonInterface $maintenant, bool $terminer): Workout
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

        // Maintenant, sans passer le début, borné au plus tôt à lui : une fin
        // d'avant le début donnerait une durée négative (#2049). Une séance
        // déjà close garde sa première fin.
        if ($terminer) {
            if ($workout->ended_at === null) {
                $workout->ended_at = $workout->finBornee(null, $maintenant);
            }

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
