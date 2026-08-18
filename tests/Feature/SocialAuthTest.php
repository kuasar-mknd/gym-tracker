<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un fournisseur qui rend l'utilisateur social donne.
     *
     * Une classe anonyme plutot qu'un double Mockery : PHPStan ne sait pas
     * typer `shouldReceive()->andReturn()`, ce qui coutait une entree de
     * baseline par appel. L'interface ne declare que deux methodes.
     */
    private function fournisseurRendant(SocialiteUser $utilisateur): Provider
    {
        return new readonly class($utilisateur) implements Provider
        {
            public function __construct(private SocialiteUser $utilisateur)
            {
            }

            public function redirect(): never
            {
                throw new \LogicException('Ce test n’emprunte pas la redirection.');
            }

            public function user(): SocialiteUser
            {
                return $this->utilisateur;
            }
        };
    }

    public function test_github_redirect_works(): void
    {
        $response = $this->get(route('social.redirect', 'github'));

        $this->assertStringContainsString('github.com/login/oauth/authorize', $response->getTargetUrl());
    }

    public function test_google_redirect_works(): void
    {
        $response = $this->get(route('social.redirect', 'google'));

        $this->assertStringContainsString('accounts.google.com/o/oauth2/auth', $response->getTargetUrl());
    }

    public function test_social_callback_creates_new_user(): void
    {
        $socialiteUser = new SocialiteUser()
            ->setRaw(['email_verified' => true])
            ->map([
                'id' => '12345',
                'email' => 'new@example.com',
                'name' => 'New User',
                'nickname' => 'newuser',
                'avatar' => 'https://example.com/avatar.jpg',
            ]);

        $provider = $this->fournisseurRendant($socialiteUser);

        \Laravel\Socialite\Facades\Socialite::shouldReceive('driver')->with('github')->andReturn($provider);

        $response = $this->get(route('social.callback', 'github'));

        $this->assertDatabaseHas('users', [
            'email' => 'new@example.com',
            'provider' => 'github',
            'provider_id' => '12345',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard'));
    }

    public function test_social_callback_logs_in_existing_user(): void
    {
        $user = \App\Models\User::factory()->create([
            'email' => 'existing@example.com',
            'provider' => 'google',
            'provider_id' => '67890',
        ]);

        $socialiteUser = new SocialiteUser()
            ->setRaw(['verified_email' => true])
            ->map([
                'id' => '67890',
                'email' => 'existing@example.com',
                'name' => 'Existing User',
                'nickname' => 'existing',
                'avatar' => 'https://example.com/avatar.jpg',
            ]);

        $provider = $this->fournisseurRendant($socialiteUser);

        \Laravel\Socialite\Facades\Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('social.callback', 'google'));

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard'));
    }

    public function test_social_callback_links_account_if_email_matches(): void
    {
        $user = \App\Models\User::factory()->create([
            'email' => 'match@example.com',
            'provider' => null,
            'provider_id' => null,
        ]);

        $socialiteUser = new SocialiteUser()
            ->setRaw(['verified' => true])
            ->map([
                'id' => '112233',
                'email' => 'match@example.com',
                'name' => 'Match User',
                'nickname' => 'match',
                'avatar' => 'https://example.com/avatar.jpg',
            ]);

        $provider = $this->fournisseurRendant($socialiteUser);

        \Laravel\Socialite\Facades\Socialite::shouldReceive('driver')->with('github')->andReturn($provider);

        $response = $this->get(route('social.callback', 'github'));

        $this->assertAuthenticatedAs($user);
        $this->assertEquals('github', $user->refresh()->provider);
        $this->assertEquals('112233', $user->refresh()->provider_id);
        $response->assertRedirect(route('dashboard'));
    }
}
