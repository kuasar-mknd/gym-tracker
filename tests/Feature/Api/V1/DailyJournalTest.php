<?php

declare(strict_types=1);

use App\Models\DailyJournal;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * A freshly created account, signed in for the request under test.
 *
 * Handed back rather than parked on $this by beforeEach: Pest binds the test
 * closure at run time, so a fixture held on the test case reaches the body
 * untyped and every read of it analyses as a possible null dereference.
 */
function signInAsJournalAuthor(): User
{
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    return $user;
}

describe('Guest', function (): void {
    test('cannot list journals', function (): void {
        getJson(route('api.v1.daily-journals.index'))->assertUnauthorized();
    });

    test('cannot create journal', function (): void {
        postJson(route('api.v1.daily-journals.store'), [])->assertUnauthorized();
    });

    test('cannot view journal', function (): void {
        $journal = DailyJournal::factory()->create();
        getJson(route('api.v1.daily-journals.show', $journal))->assertUnauthorized();
    });

    test('cannot update journal', function (): void {
        $journal = DailyJournal::factory()->create();
        putJson(route('api.v1.daily-journals.update', $journal), [])->assertUnauthorized();
    });

    test('cannot delete journal', function (): void {
        $journal = DailyJournal::factory()->create();
        deleteJson(route('api.v1.daily-journals.destroy', $journal))->assertUnauthorized();
    });
});

describe('Authenticated', function (): void {
    describe('Index', function (): void {
        test('user can list their journals', function (): void {
            $user = signInAsJournalAuthor();

            DailyJournal::factory()->count(3)->create(['user_id' => $user->id]);
            DailyJournal::factory()->create(['user_id' => User::factory()->create()->id]); // Other user's journal

            $response = getJson(route('api.v1.daily-journals.index'));

            $response->assertOk()
                ->assertJsonCount(3, 'data')
                ->assertJsonStructure([
                    'data' => [
                        '*' => ['id', 'date', 'content', 'mood_score', 'sleep_quality'],
                    ],
                    'links',
                    'meta',
                ]);
        });

        test('journals are ordered by date desc', function (): void {
            $user = signInAsJournalAuthor();

            $journal1 = DailyJournal::factory()->create(['user_id' => $user->id, 'date' => now()->subDays(2)->format('Y-m-d')]);
            $journal2 = DailyJournal::factory()->create(['user_id' => $user->id, 'date' => now()->format('Y-m-d')]);

            $response = getJson(route('api.v1.daily-journals.index'));

            $response->assertJsonPath('data.0.id', $journal2->id)
                ->assertJsonPath('data.1.id', $journal1->id);
        });
    });

    describe('Store', function (): void {
        test('user can create a journal entry', function (): void {
            $user = signInAsJournalAuthor();

            $data = [
                'date' => now()->format('Y-m-d'),
                'content' => 'Great day!',
                'mood_score' => 5,
                'sleep_quality' => 4,
                'stress_level' => 2,
                'energy_level' => 8,
                'motivation_level' => 9,
                'nutrition_score' => 5,
                'training_intensity' => 7,
            ];

            postJson(route('api.v1.daily-journals.store'), $data)
                ->assertCreated()
                ->assertJsonPath('data.date', $data['date'])
                ->assertJsonPath('data.mood_score', 5);

            assertDatabaseHas('daily_journals', [
                'user_id' => $user->id,
                'date' => $data['date'],
                'mood_score' => 5,
            ]);
        });

        test('validation: date is required', function (): void {
            signInAsJournalAuthor();

            postJson(route('api.v1.daily-journals.store'), ['content' => 'No date'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['date']);
        });

        test('validation: unique date per user', function (): void {
            $user = signInAsJournalAuthor();

            DailyJournal::factory()->create([
                'user_id' => $user->id,
                'date' => '2023-01-01',
            ]);

            postJson(route('api.v1.daily-journals.store'), [
                'date' => '2023-01-01',
                'content' => 'Duplicate',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['date']);
        });

        test('validation: numeric ranges', function (): void {
            signInAsJournalAuthor();

            $data = [
                'date' => now()->format('Y-m-d'),
                'mood_score' => 6, // max 5
                'sleep_quality' => 0, // min 1
                'stress_level' => 11, // max 10
                'energy_level' => -1, // min 1
                'nutrition_score' => 6, // max 5
            ];

            postJson(route('api.v1.daily-journals.store'), $data)
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'mood_score',
                    'sleep_quality',
                    'stress_level',
                    'energy_level',
                    'nutrition_score',
                ]);
        });
    });

    describe('Show', function (): void {
        test('user can view their journal', function (): void {
            $user = signInAsJournalAuthor();

            $journal = DailyJournal::factory()->create(['user_id' => $user->id]);

            getJson(route('api.v1.daily-journals.show', $journal))
                ->assertOk()
                ->assertJsonPath('data.id', $journal->id);
        });

        test('user cannot view others journal', function (): void {
            signInAsJournalAuthor();

            $otherUser = User::factory()->create();
            $journal = DailyJournal::factory()->create(['user_id' => $otherUser->id]);

            getJson(route('api.v1.daily-journals.show', $journal))
                ->assertNotFound();
        });
    });

    describe('Update', function (): void {
        test('user can update their journal', function (): void {
            $user = signInAsJournalAuthor();

            $journal = DailyJournal::factory()->create([
                'user_id' => $user->id,
                'mood_score' => 3,
            ]);

            putJson(route('api.v1.daily-journals.update', $journal), ['mood_score' => 5])
                ->assertOk()
                ->assertJsonPath('data.mood_score', 5);

            assertDatabaseHas('daily_journals', [
                'id' => $journal->id,
                'mood_score' => 5,
            ]);
        });

        test('user cannot update others journal', function (): void {
            signInAsJournalAuthor();

            $otherUser = User::factory()->create();
            $journal = DailyJournal::factory()->create(['user_id' => $otherUser->id]);

            putJson(route('api.v1.daily-journals.update', $journal), ['mood_score' => 5])
                ->assertNotFound();
        });

        test('validation: cannot update date to existing date', function (): void {
            $user = signInAsJournalAuthor();

            DailyJournal::factory()->create(['user_id' => $user->id, 'date' => '2023-01-01']);
            $journal = DailyJournal::factory()->create(['user_id' => $user->id, 'date' => '2023-01-02']);

            putJson(route('api.v1.daily-journals.update', $journal), ['date' => '2023-01-01'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['date']);
        });
    });

    describe('Destroy', function (): void {
        test('user can delete their journal', function (): void {
            $user = signInAsJournalAuthor();

            $journal = DailyJournal::factory()->create(['user_id' => $user->id]);

            deleteJson(route('api.v1.daily-journals.destroy', $journal))
                ->assertNoContent();

            assertDatabaseMissing('daily_journals', ['id' => $journal->id]);
        });

        test('user cannot delete others journal', function (): void {
            signInAsJournalAuthor();

            $otherUser = User::factory()->create();
            $journal = DailyJournal::factory()->create(['user_id' => $otherUser->id]);

            deleteJson(route('api.v1.daily-journals.destroy', $journal))
                ->assertNotFound();
        });
    });
});
