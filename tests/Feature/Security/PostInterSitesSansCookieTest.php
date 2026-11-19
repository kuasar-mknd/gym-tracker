<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\EcheanceDuCookie;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Appareil;

/*
 * Un POST inter-sites arrive sans cookie de session (SameSite=Lax) : sa
 * réponse 419 ne pose ni cookie de session ni XSRF-TOKEN, qui remplaceraient
 * dans le navigateur ceux du compte connecté et le déconnecteraient depuis une
 * page tierce.
 */
function interSitesProtegerCommeEnProduction(): void
{
    app()->bind(
        PreventRequestForgery::class,
        fn (Application $application): PreventRequestForgery => new class($application, $application->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        },
    );
}

/**
 * @param  TestResponse<Response>  $reponse
 * @return list<string>
 */
function interSitesCookiesPoses(TestResponse $reponse): array
{
    $poses = [];

    foreach ($reponse->headers->getCookies() as $cookie) {
        if (! EcheanceDuCookie::estPassee($cookie, Carbon::now())) {
            $poses[] = $cookie->getName();
        }
    }

    return $poses;
}

it('ne pose aucun cookie sur le 419 d\'un POST arrivé sans cookie de session', function (): void {
    interSitesProtegerCommeEnProduction();

    $reponse = new Appareil()->envoyer('POST', '/logout', [], ['Sec-Fetch-Site' => 'cross-site']);

    $reponse->assertStatus(419);
    expect(interSitesCookiesPoses($reponse))->not->toContain(config()->string('session.cookie'));
    expect(interSitesCookiesPoses($reponse))->not->toContain('XSRF-TOKEN');
});

it('garde le cookie du compte connecté face à un POST inter-sites', function (): void {
    $compte = User::factory()->create();
    $appareil = new Appareil();
    $appareil->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
    interSitesProtegerCommeEnProduction();

    $appareil->oublierLeCookie(config()->string('session.cookie'));
    $reponse = $appareil->envoyer('POST', '/logout', [], ['Sec-Fetch-Site' => 'cross-site']);

    $reponse->assertStatus(419);
    expect(interSitesCookiesPoses($reponse))->not->toContain(config()->string('session.cookie'));
});

it('pose toujours le cookie de session sur une page vue sans cookie', function (): void {
    $reponse = new Appareil()->envoyer('GET', '/login');

    expect(interSitesCookiesPoses($reponse))->toContain(config()->string('session.cookie'));
    expect(interSitesCookiesPoses($reponse))->toContain('XSRF-TOKEN');
});

it('garde le comportement ordinaire d\'un 419 qui arrive avec un cookie de session', function (): void {
    $compte = User::factory()->create();
    $appareil = new Appareil();
    $appareil->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
    interSitesProtegerCommeEnProduction();

    $reponse = $appareil->envoyer('POST', '/logout', [], ['Sec-Fetch-Site' => 'cross-site']);

    $reponse->assertStatus(419);
    expect(interSitesCookiesPoses($reponse))->toContain(config()->string('session.cookie'));
});
