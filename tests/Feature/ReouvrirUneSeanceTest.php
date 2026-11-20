<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * « Reprendre » du bilan de fin de séance (#2434) : une séance que le compte
 * vient de terminer se rouvre ; une séance d'historique, jamais.
 */
class ReouvrirUneSeanceTest extends TestCase
{
    use RefreshDatabase;

    private function seanceClose(User $compte, int $minutesDepuisLaFin): Workout
    {
        return Workout::factory()->create([
            'user_id' => $compte->id,
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subMinutes($minutesDepuisLaFin),
        ]);
    }

    public function test_une_seance_terminee_a_l_instant_se_rouvre_et_ramene_sur_elle(): void
    {
        $compte = User::factory()->create();
        $seance = $this->seanceClose($compte, 1);

        $this->actingAs($compte)->post(route('workouts.reopen', $seance))->assertRedirect(route('workouts.show', $seance));

        $this->assertNull($seance->refresh()->ended_at);
    }

    public function test_la_seance_rouverte_redevient_modifiable(): void
    {
        $compte = User::factory()->create();
        $seance = $this->seanceClose($compte, 1);

        $this->actingAs($compte)->patch(route('workouts.update', $seance), ['name' => 'Nouveau'])->assertForbidden();
        $this->actingAs($compte)->post(route('workouts.reopen', $seance));
        $this->actingAs($compte)->patch(route('workouts.update', $seance), ['name' => 'Nouveau'])->assertSessionHasNoErrors();

        $this->assertSame('Nouveau', $seance->refresh()->name);
    }

    public function test_une_seance_terminee_depuis_plus_de_quinze_minutes_ne_se_rouvre_pas(): void
    {
        $compte = User::factory()->create();
        $seance = $this->seanceClose($compte, Workout::DELAI_DE_REOUVERTURE_MINUTES + 1);

        $this->actingAs($compte)->post(route('workouts.reopen', $seance))->assertForbidden();

        $this->assertNotNull($seance->refresh()->ended_at);
    }

    public function test_la_limite_du_delai_est_incluse(): void
    {
        $compte = User::factory()->create();
        $this->travelTo(now()->startOfSecond());
        $seance = $this->seanceClose($compte, Workout::DELAI_DE_REOUVERTURE_MINUTES);

        $this->actingAs($compte)->post(route('workouts.reopen', $seance))->assertRedirect();

        $this->assertNull($seance->refresh()->ended_at);
    }

    public function test_la_seance_d_un_autre_compte_ne_se_rouvre_pas(): void
    {
        $proprietaire = User::factory()->create();
        $seance = $this->seanceClose($proprietaire, 1);

        $this->actingAs(User::factory()->create())->post(route('workouts.reopen', $seance))->assertNotFound();

        $this->assertNotNull($seance->refresh()->ended_at);
    }

    public function test_rien_ne_se_rouvre_quand_une_autre_seance_est_en_cours(): void
    {
        $compte = User::factory()->create();
        $close = $this->seanceClose($compte, 1);
        $enCours = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => now(), 'ended_at' => null]);

        $this->actingAs($compte)->post(route('workouts.reopen', $close))->assertRedirect(route('workouts.show', $enCours));

        $this->assertNotNull($close->refresh()->ended_at);
    }

    public function test_une_seance_deja_ouverte_se_rend_telle_quelle(): void
    {
        $compte = User::factory()->create();
        $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => now(), 'ended_at' => null]);

        $this->actingAs($compte)->post(route('workouts.reopen', $seance))->assertRedirect(route('workouts.show', $seance));

        $this->assertNull($seance->refresh()->ended_at);
    }

    public function test_il_faut_etre_connecte(): void
    {
        $seance = $this->seanceClose(User::factory()->create(), 1);

        $this->post(route('workouts.reopen', $seance))->assertRedirect(route('login'));
    }

    public function test_la_politique_n_affaiblit_pas_update(): void
    {
        $compte = User::factory()->create();
        $seance = $this->seanceClose($compte, 1);

        $this->assertFalse($compte->can('update', $seance));
        $this->assertTrue($compte->can('reopen', $seance));
    }
}
