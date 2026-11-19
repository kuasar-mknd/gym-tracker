<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\VerificateurDeMotsDePasseDivulgues;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as RegleDeMotDePasse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

beforeEach(function (): void {
    Cache::forget(VerificateurDeMotsDePasseDivulgues::CLEF_DE_LA_PAUSE);
});

it('est le verificateur de l’application, borné à trois secondes connexion comprise', function (): void {
    $options = [];
    Http::fake(function ($requete, array $parametres) use (&$options) {
        $options = $parametres;

        return Http::response('', 200);
    });

    $verificateur = app(UncompromisedVerifier::class);

    expect($verificateur)->toBeInstanceOf(VerificateurDeMotsDePasseDivulgues::class);

    $verificateur->verify(['value' => 'un-mot-de-passe-quelconque', 'threshold' => 0]);

    expect($options['timeout'] ?? null)->toBe(3)
        ->and($options['connect_timeout'] ?? null)->toBe(3);
});

it('refuse un mot de passe que la fuite connaît, et accepte les autres', function (): void {
    $hash = strtoupper(sha1('motdepasse-divulgue'));
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(substr($hash, 5).":42\r\n0018A45C4D1DEF81644B54AB7F969B88D65:1", 200)]);

    $regle = fn (string $valeur): bool => Validator::make(['p' => $valeur], ['p' => RegleDeMotDePasse::min(8)->uncompromised()])->passes();

    expect($regle('motdepasse-divulgue'))->toBeFalse()
        ->and($regle('un-autre-mot-de-passe'))->toBeTrue();
});

it('accepte le mot de passe quand le service ne répond pas, sans rapporter d’exception', function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => fn (): never => throw new ConnectionException('cURL error 28: timeout')]);
    $rapportees = [];
    app(Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function (Throwable $exception) use (&$rapportees): void {
        $rapportees[] = $exception;
    });

    $passe = Validator::make(['p' => 'un-mot-de-passe-quelconque'], ['p' => RegleDeMotDePasse::min(8)->uncompromised()])->passes();

    expect($passe)->toBeTrue()
        ->and($rapportees)->toBeEmpty();
});

it('ne rappelle pas le service pendant une minute après un échec', function (): void {
    $appels = 0;
    Http::fake(['api.pwnedpasswords.com/*' => function () use (&$appels): void {
        $appels++;

        throw new ConnectionException('cURL error 28: timeout');
    }]);

    $regle = fn (): bool => Validator::make(['p' => 'un-mot-de-passe-quelconque'], ['p' => RegleDeMotDePasse::min(8)->uncompromised()])->passes();

    expect($regle())->toBeTrue()
        ->and($regle())->toBeTrue()
        ->and($regle())->toBeTrue()
        ->and($appels)->toBe(1);
});

it('suspend aussi la recherche après une réponse en erreur', function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);

    $regle = fn (): bool => Validator::make(['p' => 'un-mot-de-passe-quelconque'], ['p' => RegleDeMotDePasse::min(8)->uncompromised()])->passes();

    expect($regle())->toBeTrue()
        ->and($regle())->toBeTrue();

    Http::assertSentCount(1);
});

it('plafonne le mot de passe à 255 caractères à l’inscription, à la réinitialisation et au changement', function (): void {
    $trop = str_repeat('Aa1', 86);

    post(route('register'), ['name' => 'Alex', 'email' => 'alex@example.org', 'password' => $trop, 'password_confirmation' => $trop])
        ->assertSessionHasErrors('password');

    $compte = User::factory()->create();
    $jeton = Password::createToken($compte);

    post(route('password.store'), ['token' => $jeton, 'email' => $compte->email, 'password' => $trop, 'password_confirmation' => $trop])
        ->assertSessionHasErrors('password');

    actingAs($compte)->put(route('password.update'), ['current_password' => 'password', 'password' => $trop, 'password_confirmation' => $trop])
        ->assertSessionHasErrors('password');
});

it('accepte un mot de passe de 255 caractères', function (): void {
    $limite = str_repeat('Aa1', 85);

    post(route('register'), ['name' => 'Alex', 'email' => 'alex@example.org', 'password' => $limite, 'password_confirmation' => $limite])
        ->assertSessionDoesntHaveErrors('password');
});
