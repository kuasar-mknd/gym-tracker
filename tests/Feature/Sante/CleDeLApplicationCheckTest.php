<?php

declare(strict_types=1);

use App\Support\Sante\CleDeLApplicationCheck;
use Illuminate\Support\Facades\Config;

/*
 * La clé de l'application ne doit pas être l'une de celles publiées dans
 * l'historique du dépôt : le contrôle compare des empreintes, jamais des clés.
 */

/**
 * Le contrôle, jugé comme en production.
 *
 * @param  list<string>  $empreintes
 */
function cleDeLApplicationEnProduction(string $cle, array $empreintes): string
{
    app()->detectEnvironment(fn (): string => 'production');
    Config::set('app.key', $cle);
    Config::set('app.cles_publiees', $empreintes);

    return (string) CleDeLApplicationCheck::new()->run()->status->value;
}

it('reste verte avec une clé qui n\'est pas publiée', function (): void {
    expect(cleDeLApplicationEnProduction('base64:'.base64_encode(random_bytes(32)), [hash('sha256', 'autre')]))->toBe('ok');
});

it('reste verte quand aucune empreinte n\'est connue', function (): void {
    expect(cleDeLApplicationEnProduction('base64:'.base64_encode(random_bytes(32)), []))->toBe('ok');
});

it('échoue quand l\'empreinte de la clé avec préfixe est publiée', function (): void {
    $brut = base64_encode(str_repeat('a', 32));

    expect(cleDeLApplicationEnProduction('base64:'.$brut, [hash('sha256', 'base64:'.$brut)]))->toBe('failed');
});

it('échoue quand l\'empreinte de la forme sans préfixe est publiée', function (): void {
    $brut = base64_encode(str_repeat('b', 32));

    expect(cleDeLApplicationEnProduction('base64:'.$brut, [hash('sha256', $brut)]))->toBe('failed');
});

it('ne cite jamais la clé dans le message', function (): void {
    $brut = base64_encode(str_repeat('c', 32));
    cleDeLApplicationEnProduction('base64:'.$brut, [hash('sha256', $brut)]);

    $message = CleDeLApplicationCheck::new()->run()->getNotificationMessage();

    expect($message)->not->toContain($brut)
        ->and($message)->toContain('retirer la clé publiée');
});

it('ne juge rien hors production', function (): void {
    Config::set('app.key', 'base64:'.base64_encode('x'));
    Config::set('app.cles_publiees', [hash('sha256', 'base64:'.base64_encode('x'))]);

    expect((string) CleDeLApplicationCheck::new()->run()->status->value)->toBe('ok');
});

it('échoue quand la clé publiée traîne dans les anciennes clés', function (): void {
    $brut = base64_encode(str_repeat('d', 32));
    Config::set('app.previous_keys', ['base64:'.$brut]);

    expect(cleDeLApplicationEnProduction('base64:'.base64_encode(str_repeat('e', 32)), [hash('sha256', $brut)]))->toBe('failed');
});

it('ne plante pas quand la clé est absente', function (): void {
    expect(cleDeLApplicationEnProduction('', [hash('sha256', 'autre')]))->toBe('ok');
});
