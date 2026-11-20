<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Notifications\AchievementUnlocked;
use App\Support\VersionDeCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Terminer, rouvrir, terminer (#2434) : « Reprendre » du bilan ne double ni
 * les records, ni les succès, ni leurs notifications, et ne laisse aucune
 * statistique de cache périmée.
 */
class ReouvertureNeRejoueRienTest extends TestCase
{
    use RefreshDatabase;

    private User $compte;

    private Workout $seance;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->compte = User::factory()->create();
        $this->seance = Workout::factory()->create([
            'user_id' => $this->compte->id,
            'started_at' => now()->subHour(),
            'ended_at' => null,
        ]);

        $ligne = WorkoutLine::factory()->create([
            'workout_id' => $this->seance->id,
            'exercise_id' => Exercise::factory()->create(['name' => 'Développé couché'])->id,
        ]);

        Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 5]);

        Achievement::factory()->create(['type' => 'count', 'threshold' => 1]);
        Achievement::factory()->create(['type' => 'weight_record', 'threshold' => 50]);
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function terminer(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->compte)->patchJson(route('api.v1.workouts.finish'), ['workout_id' => $this->seance->id]);
    }

    private function rouvrir(): void
    {
        $this->actingAs($this->compte)->post(route('workouts.reopen', $this->seance))->assertRedirect(route('workouts.show', $this->seance));
    }

    /**
     * @return array<string, mixed>
     */
    private function etat(): array
    {
        $compte = $this->compte->refresh();

        return [
            'records' => PersonalRecord::query()->where('user_id', $compte->id)->count(),
            'succes' => $compte->achievements()->count(),
            'serie' => $compte->current_streak,
            'derniere' => $compte->last_workout_at?->toDateString(),
        ];
    }

    public function test_la_reponse_de_la_fin_porte_le_droit_de_reprendre_et_pas_les_records(): void
    {
        $reponse = $this->terminer()
            ->assertOk()
            ->assertJsonPath('meta.peut_reprendre', true)
            ->assertJsonPath('meta.reprise_jusqu_a', $this->seance->refresh()->ended_at->copy()->addMinutes(Workout::DELAI_DE_REOUVERTURE_MINUTES)->toIso8601String());

        $meta = $reponse->json('meta');
        $this->assertIsArray($meta);
        $this->assertArrayNotHasKey('records', $meta);
    }

    public function test_le_bilan_lit_les_records_de_la_seance_par_une_route_a_part(): void
    {
        $this->terminer()->assertOk();

        $this->actingAs($this->compte)->getJson(route('workouts.records', $this->seance))
            ->assertOk()
            ->assertJsonPath('records.0.exercice', 'Développé couché')
            ->assertJsonPath('records.0.valeur', 100)
            ->assertJsonCount(1, 'records');
    }

    public function test_les_records_d_une_seance_ne_se_lisent_que_par_son_compte(): void
    {
        $this->terminer()->assertOk();

        $this->actingAs(User::factory()->create())->getJson(route('workouts.records', $this->seance))->assertNotFound();
    }

    public function test_une_fin_choisie_trop_loin_ne_laisse_pas_reprendre(): void
    {
        $this->actingAs($this->compte)->patchJson(route('api.v1.workouts.finish'), [
            'workout_id' => $this->seance->id,
            'fin_choisie' => now()->subMinutes(Workout::DELAI_DE_REOUVERTURE_MINUTES + 5)->toIso8601String(),
        ])->assertOk()->assertJsonPath('meta.peut_reprendre', false);
    }

    public function test_terminer_rouvrir_terminer_ne_double_ni_records_ni_succes_ni_notifications(): void
    {
        Notification::fake();

        $this->terminer()->assertOk();
        $apresLaPremiere = $this->etat();
        $notifiees = Notification::sent($this->compte, AchievementUnlocked::class)->count();

        $this->assertSame(2, $apresLaPremiere['succes']);
        $this->assertSame(2, $notifiees);
        $this->assertGreaterThan(0, $apresLaPremiere['records']);

        $this->rouvrir();
        $this->assertNull($this->seance->refresh()->ended_at);

        $this->terminer()->assertOk();

        $this->actingAs($this->compte)->getJson(route('workouts.records', $this->seance))
            ->assertOk()
            ->assertJsonPath('records.0.valeur', 100)
            ->assertJsonCount(1, 'records');

        $this->assertSame($apresLaPremiere, $this->etat());
        $this->assertSame($notifiees, Notification::sent($this->compte, AchievementUnlocked::class)->count());
        $this->assertNotNull($this->seance->refresh()->ended_at);
    }

    public function test_la_reouverture_et_la_nouvelle_fin_renouvellent_les_statistiques_du_cache(): void
    {
        $cle = "stats-version.seances.{$this->compte->id}";

        $this->terminer()->assertOk();
        $apresLaFin = VersionDeCache::lire($cle);

        $this->rouvrir();
        $apresLaReouverture = VersionDeCache::lire($cle);

        $this->terminer()->assertOk();
        $apresLaNouvelleFin = VersionDeCache::lire($cle);

        $this->assertNotSame($apresLaFin, $apresLaReouverture);
        $this->assertNotSame($apresLaReouverture, $apresLaNouvelleFin);
    }

    public function test_la_seance_rouverte_n_est_plus_comptee_comme_faite_puis_l_est_de_nouveau(): void
    {
        $this->terminer()->assertOk();
        $this->assertTrue($this->compte->seancesFaites()->whereKey($this->seance->id)->exists());

        $this->rouvrir();
        $this->assertFalse($this->compte->seancesFaites()->whereKey($this->seance->id)->exists());

        $this->terminer()->assertOk();
        $this->assertTrue($this->compte->seancesFaites()->whereKey($this->seance->id)->exists());
    }

    public function test_une_autre_seance_ouverte_empeche_de_reprendre_apres_la_fin(): void
    {
        $this->terminer()->assertOk();
        $autre = Workout::factory()->create(['user_id' => $this->compte->id, 'started_at' => now(), 'ended_at' => null]);

        $this->actingAs($this->compte)->post(route('workouts.reopen', $this->seance))->assertRedirect(route('workouts.show', $autre));

        $this->assertNotNull($this->seance->refresh()->ended_at);
    }

    public function test_reprendre_est_refuse_apres_le_delai(): void
    {
        $this->terminer()->assertOk();

        $this->travel(Workout::DELAI_DE_REOUVERTURE_MINUTES + 1)->minutes();

        $this->actingAs($this->compte)->post(route('workouts.reopen', $this->seance))->assertForbidden();
        $this->assertNotNull($this->seance->refresh()->ended_at);
    }

    public function test_ziggy_connait_les_routes_de_reouverture_et_de_records_cote_client(): void
    {
        /** @var list<string> $exposees */
        $exposees = config('ziggy.only');
        /** @var array<string, mixed> $routes */
        $routes = new \Tighten\Ziggy\Ziggy()->toArray()['routes'];

        $this->assertContains('workouts.*', $exposees);
        $this->assertArrayHasKey('workouts.reopen', $routes);
        $this->assertArrayHasKey('workouts.records', $routes);
    }
}
