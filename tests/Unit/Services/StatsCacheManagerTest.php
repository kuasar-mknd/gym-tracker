<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\Stats\ClesDeStats;
use App\Services\Stats\StatsCacheManager;
use Tests\TestCase;

/*
 * Invalider, c'est changer de version : les clefs relues ensuite diffèrent de
 * celles écrites avant, et la famille voisine ne bouge pas.
 */
class StatsCacheManagerTest extends TestCase
{
    public function test_clearing_workout_stats_changes_every_workout_key_and_leaves_body_keys_alone(): void
    {
        $user = User::factory()->make(['id' => 123]);
        $seance = ClesDeStats::seances($user, 'volume_trend.30');
        $mesure = ClesDeStats::mesures($user, 'body_progress.90');

        app(StatsCacheManager::class)->clearWorkoutRelatedStats($user);

        $this->assertNotSame($seance, ClesDeStats::seances($user, 'volume_trend.30'));
        $this->assertSame($mesure, ClesDeStats::mesures($user, 'body_progress.90'));
    }

    public function test_every_workout_clear_moves_the_same_version(): void
    {
        $user = User::factory()->make(['id' => 123]);
        $manager = app(StatsCacheManager::class);
        $depart = ClesDeStats::seances($user, 'weekly_volume');

        $manager->clearWorkoutMetadataStats($user);
        $apresMetadonnees = ClesDeStats::seances($user, 'weekly_volume');
        $manager->clearVolumeStats($user);
        $apresVolume = ClesDeStats::seances($user, 'weekly_volume');
        $manager->clearWorkoutRelatedStats($user);
        $apresTout = ClesDeStats::seances($user, 'weekly_volume');

        $this->assertCount(4, array_unique([$depart, $apresMetadonnees, $apresVolume, $apresTout]));
    }

    public function test_clearing_body_stats_changes_body_keys_and_leaves_workout_keys_alone(): void
    {
        $user = User::factory()->make(['id' => 123]);
        $seance = ClesDeStats::seances($user, 'volume_trend.30');
        $mesure = ClesDeStats::mesures($user, 'latest_metrics');

        app(StatsCacheManager::class)->clearBodyMeasurementStats($user);

        $this->assertNotSame($mesure, ClesDeStats::mesures($user, 'latest_metrics'));
        $this->assertSame($seance, ClesDeStats::seances($user, 'volume_trend.30'));
    }

    public function test_two_users_have_independent_versions(): void
    {
        $premier = User::factory()->make(['id' => 1]);
        $second = User::factory()->make(['id' => 2]);
        $duSecond = ClesDeStats::seances($second, 'weekly_volume');

        app(StatsCacheManager::class)->clearWorkoutRelatedStats($premier);

        $this->assertSame($duSecond, ClesDeStats::seances($second, 'weekly_volume'));
    }
}
