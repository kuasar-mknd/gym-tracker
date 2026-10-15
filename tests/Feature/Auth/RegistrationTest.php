<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    /**
     * L'inscription crée le compte en attente du lien envoyé à son adresse, et
     * ne connecte personne : la page mène à la connexion, avec un message
     * écrit pour une adresse libre comme pour une adresse prise
     * (`InscriptionMemeReponseTest`).
     */
    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('login', absolute: false))
            ->assertSessionHas('status', __('auth.inscription_recue'));

        $compte = User::query()->where('email', 'test@example.com')->sole();

        $this->assertTrue($compte->estEnAttente());
        $this->assertFalse($compte->hasVerifiedEmail());
    }
}
