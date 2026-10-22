<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FormulaireDeReinitialisation;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): true {
            $response = $this->get($this->lienDuCourriel($notification, $user));

            $response->assertStatus(200)->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Auth/ResetPassword')
                ->where('token', $notification->token)
                ->missing('compte'));

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): true {
            $lien = $this->lienDuCourriel($notification, $user);

            $response = $this->post('/reset-password', FormulaireDeReinitialisation::depuisLeLien($lien, 'password'));

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    /**
     * Le lien du courriel de réinitialisation. Depuis Laravel 13.38, `toMail()`
     * se déclare `MailMessage|Mailable` (un rappel `toMailUsing()` peut rendre
     * un Mailable) ; l'application rend un `MailMessage`, qui porte le lien.
     */
    private function lienDuCourriel(ResetPassword $notification, User $user): string
    {
        $courriel = $notification->toMail($user);
        $this->assertInstanceOf(MailMessage::class, $courriel);

        return (string) $courriel->actionUrl;
    }
}
