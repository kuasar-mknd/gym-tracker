<?php

namespace Tests\Feature\Api\V1;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HabitControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_habits()
    {
        $user = User::factory()->create();
        Habit::factory()->count(3)->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.habits.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_user_can_create_habit()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $data = [
            'name' => 'Drink Water',
            'goal_times_per_week' => 7,
            'color' => '#0000FF',
        ];

        $response = $this->postJson(route('api.v1.habits.store'), $data);

        $response->assertCreated()
            ->assertJsonFragment(['name' => 'Drink Water']);

        $this->assertDatabaseHas('habits', [
            'user_id' => $user->id,
            'name' => 'Drink Water',
        ]);
    }

    public function test_user_can_update_habit()
    {
        $user = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $data = ['name' => 'Updated Name'];

        $response = $this->putJson(route('api.v1.habits.update', $habit), $data);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Updated Name']);

        $this->assertDatabaseHas('habits', [
            'id' => $habit->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_user_can_delete_habit()
    {
        $user = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.v1.habits.destroy', $habit));

        $response->assertNoContent();

        $this->assertDatabaseMissing('habits', ['id' => $habit->id]);
    }

    public function test_user_cannot_access_others_habits()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);

        $this->getJson(route('api.v1.habits.show', $habit))->assertForbidden();
        $this->putJson(route('api.v1.habits.update', $habit), ['name' => 'Hack'])->assertForbidden();
        $this->deleteJson(route('api.v1.habits.destroy', $habit))->assertForbidden();
    }

    public function test_user_can_create_habit_log()
    {
        $user = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $data = [
            'habit_id' => $habit->id,
            'date' => now()->format('Y-m-d'),
            'notes' => 'Drank 2L',
        ];

        $response = $this->postJson(route('api.v1.habit-logs.store'), $data);

        $response->assertCreated();
        $this->assertDatabaseHas('habit_logs', ['habit_id' => $habit->id]);
    }

    public function test_user_cannot_log_for_others_habit()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);

        $data = [
            'habit_id' => $habit->id,
            'date' => now()->format('Y-m-d'),
        ];

        $response = $this->postJson(route('api.v1.habit-logs.store'), $data);

        $response->assertUnprocessable(); // Validation should fail due to exists rule scope
    }

    public function test_user_can_list_habit_logs()
    {
        $user = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $user->id]);
        HabitLog::factory()->count(3)->create(['habit_id' => $habit->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.habit-logs.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }
}
