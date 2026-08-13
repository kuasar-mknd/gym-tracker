<?php

declare(strict_types=1);

use Laravel\Socialite\Facades\Socialite;

/**
 * A social button that cannot work must not be on the page.
 *
 * Both auth screens ask for `social_login_enabled` before drawing their three
 * buttons, and nothing ever shared it — so the fallback won every time and all
 * three were drawn unconditionally. Apple then answered 500 on every click: the
 * package sat in composer.json without ever being announced to Socialite, and
 * no credentials were configured either.
 *
 * Two things are checked here, because fixing one without the other leaves the
 * button broken: that the driver resolves at all, and that the page only offers
 * providers whose credentials are complete.
 */
it('resolves every provider the login page can offer', function (string $provider): void {
    // Apple is a community package: Socialite has no createAppleDriver, so it
    // only exists once an event listener extends Socialite with it.
    expect(fn () => Socialite::driver($provider))->not->toThrow(InvalidArgumentException::class);
})->with(['google', 'github', 'apple']);

it('offers a provider only when both halves of its credentials are set', function (): void {
    config([
        'services.google.client_id' => 'id',
        'services.google.client_secret' => 'secret',
        // A client id with no secret cannot complete the exchange. Offering the
        // button anyway sends the user to an error page instead of to the
        // provider — which is exactly what Apple did.
        'services.github.client_id' => 'id',
        'services.github.client_secret' => null,
        'services.apple.client_id' => null,
        'services.apple.client_secret' => null,
    ]);

    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page
            ->where('social_login_enabled.google', true)
            ->where('social_login_enabled.github', false)
            ->where('social_login_enabled.apple', false)
        );
});

it('says so on the registration page too', function (): void {
    config([
        'services.google.client_id' => 'id',
        'services.google.client_secret' => 'secret',
        'services.github.client_id' => null,
        'services.github.client_secret' => null,
        'services.apple.client_id' => null,
        'services.apple.client_secret' => null,
    ]);

    // The registration page carried the same three buttons with no guard at
    // all, so it kept offering Apple even once the login page had stopped.
    $this->get(route('register'))
        ->assertInertia(fn ($page) => $page
            ->where('social_login_enabled.google', true)
            ->where('social_login_enabled.apple', false)
        );
});
