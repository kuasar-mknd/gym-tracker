<?php

namespace Tests\Feature\Api;

use App\Models\DailyJournal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DailyJournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_daily_journals(): void
    {
        $user = User::factory()->create();
        DailyJournal::factory()->count(3)->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.daily-journals.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_can_create_daily_journal(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $data = [
            'date' => '2023-01-01',
            'content' => 'Test content',
            'mood_score' => 5,
        ];

        $response = $this->postJson(route('api.v1.daily-journals.store'), $data);

        $response->assertCreated()
            ->assertJsonFragment(['date' => '2023-01-01']);

        $this->assertDatabaseHas('daily_journals', [
            'user_id' => $user->id,
            'date' => '2023-01-01',
        ]);
    }

    public function test_cannot_create_duplicate_daily_journal_for_same_date(): void
    {
        $user = User::factory()->create();
        DailyJournal::factory()->create(['user_id' => $user->id, 'date' => '2023-01-01']);
        Sanctum::actingAs($user);

        $data = [
            'date' => '2023-01-01',
            'content' => 'Duplicate',
        ];

        $response = $this->postJson(route('api.v1.daily-journals.store'), $data);

        $response->assertUnprocessable();
    }

    public function test_can_show_daily_journal(): void
    {
        $user = User::factory()->create();
        $journal = DailyJournal::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.daily-journals.show', $journal));

        $response->assertOk()
            ->assertJsonFragment(['id' => $journal->id]);
    }

    public function test_cannot_show_other_users_daily_journal(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $journal = DailyJournal::factory()->create(['user_id' => $otherUser->id]);
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.daily-journals.show', $journal));

        $response->assertForbidden();
    }

    public function test_can_update_daily_journal(): void
    {
        $user = User::factory()->create();
        $journal = DailyJournal::factory()->create(['user_id' => $user->id, 'mood_score' => 1]);
        Sanctum::actingAs($user);

        $data = [
            'mood_score' => 5,
        ];

        $response = $this->putJson(route('api.v1.daily-journals.update', $journal), $data);

        $response->assertOk()
            ->assertJsonFragment(['mood_score' => 5]);

        $this->assertDatabaseHas('daily_journals', [
            'id' => $journal->id,
            'mood_score' => 5,
        ]);
    }

    public function test_cannot_update_daily_journal_to_existing_date(): void
    {
        $user = User::factory()->create();
        DailyJournal::factory()->create(['user_id' => $user->id, 'date' => '2023-01-01']);
        $journal = DailyJournal::factory()->create(['user_id' => $user->id, 'date' => '2023-01-02']);
        Sanctum::actingAs($user);

        $data = [
            'date' => '2023-01-01',
        ];

        $response = $this->putJson(route('api.v1.daily-journals.update', $journal), $data);

        $response->assertUnprocessable();
    }

    public function test_can_delete_daily_journal(): void
    {
        $user = User::factory()->create();
        $journal = DailyJournal::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.v1.daily-journals.destroy', $journal));

        $response->assertNoContent();

        $this->assertDatabaseMissing('daily_journals', ['id' => $journal->id]);
    }
}
