<?php

namespace Tests\Feature\Api\V1;

use App\Models\Habit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HabitTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_habits(): void
    {
        $user = User::factory()->create();
        Habit::factory()->count(3)->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.habits.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_user_can_create_habit(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $data = [
            'name' => 'New Habit',
            'goal_times_per_week' => 5,
        ];

        $response = $this->postJson(route('api.v1.habits.store'), $data);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'New Habit')
            ->assertJsonPath('data.goal_times_per_week', 5);

        $this->assertDatabaseHas('habits', [
            'user_id' => $user->id,
            'name' => 'New Habit',
        ]);
    }

    public function test_user_can_update_habit(): void
    {
        $user = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $data = [
            'name' => 'Updated Habit',
        ];

        $response = $this->putJson(route('api.v1.habits.update', $habit), $data);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Habit');

        $this->assertDatabaseHas('habits', [
            'id' => $habit->id,
            'name' => 'Updated Habit',
        ]);
    }

    public function test_user_cannot_update_others_habit(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $otherUser->id]);
        Sanctum::actingAs($user);

        $response = $this->putJson(route('api.v1.habits.update', $habit), [
            'name' => 'Hacked',
        ]);

        $response->assertForbidden();
    }

    public function test_user_can_delete_habit(): void
    {
        $user = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.v1.habits.destroy', $habit));

        $response->assertNoContent();
        $this->assertDatabaseMissing('habits', ['id' => $habit->id]);
    }

    public function test_user_can_log_habit(): void
    {
        $user = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $data = [
            'habit_id' => $habit->id,
            'date' => '2023-01-01',
            'notes' => 'Done',
        ];

        $response = $this->postJson(route('api.v1.habit-logs.store'), $data);

        $response->assertCreated();
        $this->assertDatabaseHas('habit_logs', [
            'habit_id' => $habit->id,
            'date' => '2023-01-01 00:00:00',
        ]);
    }

    public function test_user_cannot_log_others_habit(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $otherUser->id]);
        Sanctum::actingAs($user);

        $data = [
            'habit_id' => $habit->id,
            'date' => '2023-01-01',
        ];

        $response = $this->postJson(route('api.v1.habit-logs.store'), $data);

        $response->assertStatus(422); // Validation fails because of Rule::exists with user check
    }
}
