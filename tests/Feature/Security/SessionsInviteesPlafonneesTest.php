<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\get;
use function Pest\Laravel\withCredentials;

beforeEach(function (): void {
    config(['session.invites_par_minute' => 3]);
    RateLimiter::clear('sessions-neuves:127.0.0.1');
});

it('refuse en 429, sans poser de cookie, la requête sans session au-delà du plafond', function (): void {
    foreach (range(1, 3) as $_) {
        get(route('login'))->assertOk();
    }

    $refus = get(route('login'));

    $refus->assertStatus(429);
    expect($refus->headers->get('Retry-After'))->not->toBeNull()
        ->and($refus->headers->getCookies())->toBeEmpty();
});

it('ne compte pas la requête qui porte une session que le magasin tient', function (): void {
    $premiere = get(route('login'))->assertOk();
    $cookie = $premiere->getCookie(config()->string('session.cookie'), decrypt: false);

    expect($cookie)->not->toBeNull();

    foreach (range(1, 8) as $_) {
        withCredentials()->withUnencryptedCookie(config()->string('session.cookie'), (string) $cookie?->getValue())
            ->get(route('login'))->assertOk();
    }
});

it('plafonne aussi les routes de connexion sociale sans cookie', function (): void {
    foreach (range(1, 3) as $_) {
        get(route('social.redirect', 'github'))->assertRedirect();
    }

    get(route('social.redirect', 'github'))->assertStatus(429);
});

it('laisse passer le préchauffage, qui n’écrit aucune session', function (): void {
    foreach (range(1, 8) as $_) {
        get(route('login'), ['X-Copies-Hors-Ligne' => 'prechauffage'])->assertOk();
    }
});

it('compte chaque adresse à part, et une adresse IPv6 par son préfixe de 64 bits', function (): void {
    foreach (range(1, 3) as $_) {
        get(route('login'))->assertOk();
    }

    get(route('login'))->assertStatus(429);

    foreach (range(1, 3) as $_) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get(route('login'))->assertOk();
    }

    foreach (range(1, 3) as $position) {
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::'.$position])->get(route('login'))->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::ff'])->get(route('login'))->assertStatus(429);
});
