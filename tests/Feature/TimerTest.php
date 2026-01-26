<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\TimerPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_view_timer_page()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('tools.interval-timer'));

        $response->assertStatus(200);
    }

    public function test_can_create_preset()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('tools.interval-timer.store'), [
            'name' => 'Tabata',
            'work_seconds' => 20,
            'rest_seconds' => 10,
            'rounds' => 8,
            'warmup_seconds' => 10,
            'cooldown_seconds' => 60,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('timer_presets', [
            'user_id' => $user->id,
            'name' => 'Tabata',
            'work_seconds' => 20,
        ]);
    }
}
