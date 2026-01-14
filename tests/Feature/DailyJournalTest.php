<?php

namespace Tests\Feature;

use App\Models\DailyJournal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyJournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_journal_index(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('daily-journals.index'));

        $response->assertStatus(200);
    }

    public function test_user_can_create_journal_entry(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->post(route('daily-journals.store'), [
            'date' => now()->format('Y-m-d'),
            'content' => 'Test journal content',
            'mood_score' => 5,
            'sleep_quality' => 4,
            'stress_level' => 2,
            'energy_level' => 8,
            'motivation_level' => 9,
            'nutrition_score' => 4,
            'training_intensity' => 7,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('daily_journals', [
            'user_id' => $user->id,
            'content' => 'Test journal content',
            'energy_level' => 8,
        ]);
    }

    public function test_user_can_update_existing_entry(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $journal = DailyJournal::factory()->create([
            'user_id' => $user->id,
            'date' => now()->format('Y-m-d'),
            'content' => 'Old content',
        ]);

        $response = $this->post(route('daily-journals.store'), [
            'date' => now()->format('Y-m-d'),
            'content' => 'New content',
            'mood_score' => 3,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('daily_journals', [
            'id' => $journal->id,
            'content' => 'New content',
            'mood_score' => 3,
        ]);
    }

    public function test_user_cannot_delete_others_journal(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $journal = DailyJournal::create([
            'user_id' => $user2->id,
            'date' => now()->format('Y-m-d'),
            'content' => 'Someone else journal',
        ]);

        $this->actingAs($user1);
        $response = $this->delete(route('daily-journals.destroy', $journal->id));

        $response->assertStatus(403);
        $this->assertDatabaseHas('daily_journals', ['id' => $journal->id]);
    }
}
