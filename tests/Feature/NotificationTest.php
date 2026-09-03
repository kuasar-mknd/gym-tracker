<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Notifications\PersonalRecordAchieved;
use App\Notifications\TrainingReminder;
use App\Services\PersonalRecordService;
use Carbon\CarbonImmutable;
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

        new PersonalRecordService()->syncSetPRs($set);

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

        new PersonalRecordService()->syncSetPRs($set);

        Notification::assertNotSentTo($user, PersonalRecordAchieved::class);
    }

    /**
     * Le rappel part a 18 h, les jours choisis, quand aucune seance n'a
     * commence dans la journee. L'horloge est arretee un lundi soir : la
     * commande compare a « aujourd'hui », donc un test qui la laisse courir
     * change de sens selon le jour ou il tourne.
     */
    public function test_training_reminder_command_notifies_users_who_chose_today_and_have_not_trained(): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow('2026-09-07 18:00:00');
        $user = User::factory()->create();

        $user->notificationPreferences()->create([
            'type' => 'training_reminder',
            'is_enabled' => true,
            'days' => [1, 3],
        ]);

        // Derniere seance hier soir : rien aujourd'hui.
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => '2026-09-06 20:00:00',
        ]);

        Artisan::call('app:remind-training');

        Notification::assertSentTo($user, TrainingReminder::class);
    }

    public function test_training_reminder_command_does_not_notify_users_who_trained_today(): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow('2026-09-07 18:00:00');
        $user = User::factory()->create();

        $user->notificationPreferences()->create([
            'type' => 'training_reminder',
            'is_enabled' => true,
            'days' => [1],
        ]);

        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => '2026-09-07 09:00:00',
        ]);

        Artisan::call('app:remind-training');

        Notification::assertNotSentTo($user, TrainingReminder::class);
    }

    public function test_training_reminder_command_skips_days_the_user_did_not_choose(): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow('2026-09-07 18:00:00');
        $user = User::factory()->create();

        // Mardi et jeudi seulement ; nous sommes lundi.
        $user->notificationPreferences()->create([
            'type' => 'training_reminder',
            'is_enabled' => true,
            'days' => [2, 4],
        ]);

        Artisan::call('app:remind-training');

        Notification::assertNotSentTo($user, TrainingReminder::class);
    }

    public function test_training_reminder_command_treats_no_choice_as_every_day(): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow('2026-09-07 18:00:00');
        $user = User::factory()->create();

        // Preference d'avant la colonne : pas de jours choisis.
        $user->notificationPreferences()->create([
            'type' => 'training_reminder',
            'is_enabled' => true,
        ]);

        Artisan::call('app:remind-training');

        Notification::assertSentTo($user, TrainingReminder::class);
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
            'push_preferences' => [
                'personal_record' => true,
                'training_reminder' => false,
            ],
            'days' => [
                'training_reminder' => [1, 3, 5],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'type' => 'personal_record',
            'is_enabled' => false,
        ]);
        $rappel = $user->notificationPreferences()->where('type', 'training_reminder')->sole();
        $this->assertTrue($rappel->is_enabled);
        $this->assertSame([1, 3, 5], $rappel->days);
    }
}
