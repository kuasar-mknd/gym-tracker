<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AdresseDuCompteChangee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    /**
     * Changer l'adresse sans le mot de passe actuel était accepté : une session
     * ouverte par quelqu'un d'autre pouvait se donner l'adresse, donc la
     * réinitialisation du mot de passe. Le détail est dans
     * `tests/Feature/Security/ChangementDAdresseTest.php`.
     */
    public function test_profile_information_cannot_change_the_email_without_the_current_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['name' => 'Ancien Nom']);
        $adresse = $user->email;

        $response = $this
            ->actingAs($user)
            ->from('/profile/edit')
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/profile/edit');

        $user->refresh();

        $this->assertSame('Ancien Nom', $user->name);
        $this->assertSame($adresse, $user->email);
        $this->assertNotNull($user->email_verified_at);
        Notification::assertNothingSent();
    }

    public function test_profile_information_can_be_updated_with_the_current_password(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $ancienneAdresse = $user->email;

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'current_password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile/edit');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentOnDemand(
            AdresseDuCompteChangee::class,
            fn (AdresseDuCompteChangee $avis, array $canaux, AnonymousNotifiable $destinataire): bool => $destinataire->routes === ['mail' => $ancienneAdresse],
        );
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile/edit');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    public function test_notification_preferences_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile/preferences', [
                'preferences' => [
                    'daily_reminder' => true,
                ],
                'push_preferences' => [
                    'daily_reminder' => true,
                ],
                'days' => [
                    'daily_reminder' => [2, 4, 6],
                ],
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile/edit');

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'type' => 'daily_reminder',
            'is_enabled' => true,
            'is_push_enabled' => true,
        ]);
        $this->assertSame([2, 4, 6], $user->notificationPreferences()->where('type', 'daily_reminder')->sole()->days);
    }
}
