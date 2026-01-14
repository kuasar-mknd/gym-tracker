<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Notifications\PersonalRecordAchieved;
use App\Notifications\TrainingReminder;
use App\Services\PersonalRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pr_triggers_notification_if_enabled(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        // Enable notification
        $user->notificationPreferences()->create([
            'type' => 'personal_record',
            'is_enabled' => true,
        ]);

        $exercise = Exercise::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
        $set = Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => 100,
            'reps' => 10,
        ]);

        (new PersonalRecordService)->syncSetPRs($set);

        Notification::assertSentTo($user, PersonalRecordAchieved::class);
    }

    public function test_pr_does_not_trigger_notification_if_disabled(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        // Explicitly disable notification
        $user->notificationPreferences()->create([
            'type' => 'personal_record',
            'is_enabled' => false,
        ]);

        $exercise = Exercise::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
        $set = Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => 100,
            'reps' => 10,
        ]);

        (new PersonalRecordService)->syncSetPRs($set);

        Notification::assertNotSentTo($user, PersonalRecordAchieved::class);
    }

    public function test_training_reminder_command_notifies_inactive_users(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        // Enable reminder
        $user->notificationPreferences()->create([
            'type' => 'training_reminder',
            'is_enabled' => true,
        ]);

        // No workouts for user

        Artisan::call('app:remind-training');

        Notification::assertSentTo($user, TrainingReminder::class);
    }

    public function test_training_reminder_command_does_not_notify_active_users(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $user->notificationPreferences()->create([
            'type' => 'training_reminder',
            'is_enabled' => true,
        ]);

        // Recent workout
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
        ]);

        Artisan::call('app:remind-training');

        Notification::assertNotSentTo($user, TrainingReminder::class);
    }

    public function test_user_can_update_notification_preferences(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->patch(route('profile.preferences.update'), [
            'preferences' => [
                'personal_record' => false,
                'training_reminder' => true,
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'type' => 'personal_record',
            'is_enabled' => false,
        ]);
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'type' => 'training_reminder',
            'is_enabled' => true,
        ]);
    }
}
