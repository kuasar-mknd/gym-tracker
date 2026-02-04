<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\WilksScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WilksScoreControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_user_scores(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        WilksScore::factory()->count(3)->create(['user_id' => $user->id]);
        WilksScore::factory()->count(2)->create(); // Others

        $response = $this->getJson(route('api.v1.wilks-scores.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_store_creates_new_score(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $data = [
            'body_weight' => 80,
            'lifted_weight' => 400,
            'gender' => 'male',
            'unit' => 'kg',
        ];

        $response = $this->postJson(route('api.v1.wilks-scores.store'), $data);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'score', 'body_weight', 'lifted_weight', 'gender', 'unit']]);

        $this->assertDatabaseHas('wilks_scores', [
            'user_id' => $user->id,
            'body_weight' => 80,
            'lifted_weight' => 400,
        ]);
    }

    public function test_show_returns_score(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $score = WilksScore::factory()->create(['user_id' => $user->id]);

        $response = $this->getJson(route('api.v1.wilks-scores.show', $score));

        $response->assertOk()
            ->assertJson(['data' => ['id' => $score->id]]);
    }

    public function test_update_updates_score_and_recalculates(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $score = WilksScore::factory()->create([
            'user_id' => $user->id,
            'body_weight' => 80,
            'lifted_weight' => 400,
            'gender' => 'male',
            'unit' => 'kg',
            'score' => 100, // Dummy
        ]);

        $data = [
            'body_weight' => 90,
            'lifted_weight' => 450,
            'gender' => 'male',
            'unit' => 'kg',
        ];

        $response = $this->putJson(route('api.v1.wilks-scores.update', $score), $data);

        $response->assertOk();

        $score->refresh();
        $this->assertEquals(90, $score->body_weight);
        $this->assertEquals(450, $score->lifted_weight);
        $this->assertNotEquals(100, $score->score); // Should change
    }

    public function test_destroy_deletes_score(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $score = WilksScore::factory()->create(['user_id' => $user->id]);

        $response = $this->deleteJson(route('api.v1.wilks-scores.destroy', $score));

        $response->assertNoContent();
        $this->assertDatabaseMissing('wilks_scores', ['id' => $score->id]);
    }

    public function test_cannot_access_other_users_score(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $score = WilksScore::factory()->create(['user_id' => $otherUser->id]);

        $this->getJson(route('api.v1.wilks-scores.show', $score))->assertForbidden();
        $this->putJson(route('api.v1.wilks-scores.update', $score), [])->assertForbidden();
        $this->deleteJson(route('api.v1.wilks-scores.destroy', $score))->assertForbidden();
    }
}
