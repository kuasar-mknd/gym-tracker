<?php

declare(strict_types=1);

use App\Support\EcheanceDuCookie;
use Illuminate\Cookie\CookieJar;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Cookie;

/*
 * L'échéance d'un cookie se juge à l'horloge de l'application, celle qui l'a
 * calculée, et non à la vraie heure que lit `Cookie::isCleared()` (#2048).
 * Chaque cas qui arrête l'horloge loin de la vraie heure montre aussi ce que
 * `isCleared()` en dirait : le contraire.
 */

afterEach(function (): void {
    Carbon::setTestNow();
});

it('suit la règle de isCleared() à l’instant donné : passée dès la seconde qui suit l’échéance, pas à l’échéance même', function (int $decalage, bool $passee): void {
    $maintenant = Carbon::parse('2026-10-17 18:04:00');
    $cookie = Cookie::create('gt_copies', 'cle', $maintenant->getTimestamp() + $decalage);

    expect(EcheanceDuCookie::estPassee($cookie, $maintenant))->toBe($passee);
})->with([
    'échéance une seconde plus tôt' => [-1, true],
    'échéance à l’instant même' => [0, false],
    'échéance une seconde plus tard' => [1, false],
]);

it('ne dit jamais passé un cookie sans échéance, qui finit avec le navigateur', function (): void {
    $cookie = Cookie::create('gt_copies', 'cle', 0);

    expect(EcheanceDuCookie::estPassee($cookie, Carbon::parse('2099-01-01 00:00:00')))->toBeFalse()
        ->and(EcheanceDuCookie::estPassee($cookie, Carbon::parse('1990-01-01 00:00:00')))->toBeFalse();
});

it('garde un cookie posé sous une horloge arrêtée loin dans le passé, que isCleared() dit effacé', function (): void {
    Carbon::setTestNow('2025-06-14 18:04:00');
    $cookie = new CookieJar()->make('laravel-session', 'session', 120);

    expect(EcheanceDuCookie::estPassee($cookie, Carbon::now()))->toBeFalse()
        ->and($cookie->isCleared())->toBeTrue();
});

it('jette un cookie effacé sous une horloge arrêtée loin dans l’avenir, que isCleared() dit vivant', function (): void {
    // Dix ans après la vraie heure : l'effacement, cinq ans avant l'horloge, tombe encore après elle.
    Carbon::setTestNow(Carbon::createFromTimestamp(time())->addYears(10));
    $cookie = new CookieJar()->forget('laravel-session');

    expect(EcheanceDuCookie::estPassee($cookie, Carbon::now()))->toBeTrue()
        ->and($cookie->isCleared())->toBeFalse();
});
