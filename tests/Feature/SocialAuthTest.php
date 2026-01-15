<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

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
        $socialiteUser = \Mockery::mock('Laravel\Socialite\Two\User');
        $socialiteUser->shouldReceive('getId')->andReturn('12345');
        $socialiteUser->shouldReceive('getEmail')->andReturn('new@example.com');
        $socialiteUser->shouldReceive('getName')->andReturn('New User');
        $socialiteUser->shouldReceive('getNickname')->andReturn('newuser');
        $socialiteUser->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.jpg');
        $socialiteUser->user = ['email_verified' => true];

        $provider = \Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $provider->shouldReceive('user')->andReturn($socialiteUser);

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

        $socialiteUser = \Mockery::mock('Laravel\Socialite\Two\User');
        $socialiteUser->shouldReceive('getId')->andReturn('67890');
        $socialiteUser->shouldReceive('getEmail')->andReturn('existing@example.com');
        $socialiteUser->shouldReceive('getName')->andReturn('Existing User');
        $socialiteUser->shouldReceive('getNickname')->andReturn('existing');
        $socialiteUser->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.jpg');
        $socialiteUser->user = ['verified_email' => true];

        $provider = \Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $provider->shouldReceive('user')->andReturn($socialiteUser);

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

        $socialiteUser = \Mockery::mock('Laravel\Socialite\Two\User');
        $socialiteUser->shouldReceive('getId')->andReturn('112233');
        $socialiteUser->shouldReceive('getEmail')->andReturn('match@example.com');
        $socialiteUser->shouldReceive('getName')->andReturn('Match User');
        $socialiteUser->shouldReceive('getNickname')->andReturn('match');
        $socialiteUser->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.jpg');
        $socialiteUser->user = ['verified' => true];

        $provider = \Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        \Laravel\Socialite\Facades\Socialite::shouldReceive('driver')->with('github')->andReturn($provider);

        $response = $this->get(route('social.callback', 'github'));

        $this->assertAuthenticatedAs($user);
        $this->assertEquals('github', $user->fresh()->provider);
        $this->assertEquals('112233', $user->fresh()->provider_id);
        $response->assertRedirect(route('dashboard'));
    }
}
