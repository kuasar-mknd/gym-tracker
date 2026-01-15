<?php

namespace Tests\Feature\Api;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_personal_records()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create();
        $user->personalRecords()->create([
            'exercise_id' => $exercise->id,
            'type' => '1RM',
            'value' => 100,
            'achieved_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson(route('api.v1.personal-records.index'));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_can_create_personal_record()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create();

        $data = [
            'exercise_id' => $exercise->id,
            'type' => '1RM',
            'value' => 120,
            'achieved_at' => now()->toIso8601String(),
        ];

        $response = $this->actingAs($user)->postJson(route('api.v1.personal-records.store'), $data);

        $response->assertStatus(201)
            ->assertJsonPath('data.value', '120.00');

        $this->assertDatabaseHas('personal_records', [
            'user_id' => $user->id,
            'value' => 120,
        ]);
    }

    public function test_can_show_personal_record()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create();
        $pr = $user->personalRecords()->create([
            'exercise_id' => $exercise->id,
            'type' => '1RM',
            'value' => 100,
            'achieved_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson(route('api.v1.personal-records.show', $pr));

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $pr->id);
    }

    public function test_can_update_personal_record()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create();
        $pr = $user->personalRecords()->create([
            'exercise_id' => $exercise->id,
            'type' => '1RM',
            'value' => 100,
            'achieved_at' => now(),
        ]);

        $response = $this->actingAs($user)->putJson(route('api.v1.personal-records.update', $pr), [
            'value' => 105,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.value', '105.00');
    }

    public function test_can_delete_personal_record()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create();
        $pr = $user->personalRecords()->create([
            'exercise_id' => $exercise->id,
            'type' => '1RM',
            'value' => 100,
            'achieved_at' => now(),
        ]);

        $response = $this->actingAs($user)->deleteJson(route('api.v1.personal-records.destroy', $pr));

        $response->assertStatus(204);
        $this->assertDatabaseMissing('personal_records', ['id' => $pr->id]);
    }

    public function test_cannot_access_others_personal_record()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $exercise = Exercise::factory()->create();
        $pr = $user1->personalRecords()->create([
            'exercise_id' => $exercise->id,
            'type' => '1RM',
            'value' => 100,
            'achieved_at' => now(),
        ]);

        $response = $this->actingAs($user2)->getJson(route('api.v1.personal-records.show', $pr));
        $response->assertStatus(403);

        $response = $this->actingAs($user2)->putJson(route('api.v1.personal-records.update', $pr), ['value' => 200]);
        $response->assertStatus(403);

        $response = $this->actingAs($user2)->deleteJson(route('api.v1.personal-records.destroy', $pr));
        $response->assertStatus(403);
    }
}
