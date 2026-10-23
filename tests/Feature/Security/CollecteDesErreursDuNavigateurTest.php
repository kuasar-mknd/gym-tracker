<?php

declare(strict_types=1);

use App\Filament\Resources\ErreursNavigateur\Pages\ListErreursNavigateur;
use App\Models\ErreurNavigateur;
use App\Models\ExceptionEnregistree;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

/*
 * Le point de collecte des erreurs du navigateur (`erreurs-navigateur.store`)
 * se plafonne et se regroupe lui-même, sans compter sur le rapporteur de la
 * page : un limiteur propre à la route, par compte
 * (`ErreurNavigateur::RAPPORTS_PAR_MINUTE` et `RAPPORTS_PAR_JOUR`), au-delà
 * duquel la réponse reste 204 sans rien écrire, pour que le client ne
 * réessaie pas ; et une même erreur (même empreinte) du même compte ne
 * s'écrit qu'une fois par fenêtre (`ErreurNavigateur::SECONDES_DU_REGROUPEMENT`).
 * Ni le plafond ni le regroupement d'un compte ne touchent un autre compte.
 * La ligne et la colonne ne s'admettent qu'en nombres entiers, la forme
 * qu'envoie la page : un nombre écrit en texte est refusé sans rien écrire,
 * exception comprise. Une erreur effacée du panneau rend sa clé de
 * regroupement : elle s'écrit de nouveau si elle se reproduit.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-22 10:00:00');
});

/**
 * Un rapport comme la page l'envoie.
 *
 * @return array<string, int|string>
 */
function collecteDesErreursRapport(string $message = 'Boum'): array
{
    return [
        'type' => 'error',
        'message' => $message,
        'source' => 'https://example.org/build/assets/app-abc.js',
        'ligne' => 3,
        'colonne' => 7,
        'url' => 'https://example.org/seances',
        'agent' => 'Mozilla/5.0',
    ];
}

/**
 * Les erreurs gardées pour un compte.
 */
function collecteDesErreursGardees(User $compte): int
{
    return ErreurNavigateur::query()->where('user_id', $compte->id)->count();
}

it('ne garde qu’une ligne de cent rapports identiques d’un même compte, et répond 204 à chacun', function (): void {
    $compte = User::factory()->create();
    $this->actingAs($compte);

    foreach (range(1, 100) as $envoi) {
        $this->postJson(route('erreurs-navigateur.store'), collecteDesErreursRapport())->assertNoContent();
    }

    expect(collecteDesErreursGardees($compte))->toBe(1);
});

it('regroupe une même erreur du même compte sur sa fenêtre, limiteur ôté, et la garde de nouveau ensuite', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    $compte = User::factory()->create();
    $this->actingAs($compte);

    foreach (range(1, 100) as $envoi) {
        $this->postJson(route('erreurs-navigateur.store'), collecteDesErreursRapport())->assertNoContent();
    }

    expect(collecteDesErreursGardees($compte))->toBe(1);

    $this->postJson(route('erreurs-navigateur.store'), collecteDesErreursRapport('Une autre erreur'))->assertNoContent();
    expect(collecteDesErreursGardees($compte))->toBe(2);

    $this->travel(ErreurNavigateur::SECONDES_DU_REGROUPEMENT - 1)->seconds();
    $this->postJson(route('erreurs-navigateur.store'), collecteDesErreursRapport())->assertNoContent();
    expect(collecteDesErreursGardees($compte))->toBe(2);

    $this->travel(2)->seconds();
    $this->postJson(route('erreurs-navigateur.store'), collecteDesErreursRapport())->assertNoContent();
    expect(collecteDesErreursGardees($compte))->toBe(3);
});

it('n’écrit plus rien au-delà du plafond par minute, répond toujours 204, et reprend la minute suivante', function (): void {
    $compte = User::factory()->create();
    $this->actingAs($compte);

    foreach (range(1, ErreurNavigateur::RAPPORTS_PAR_MINUTE + 5) as $envoi) {
        $this->postJson(route('erreurs-navigateur.store'), collecteDesErreursRapport("Boum {$envoi}"))->assertNoContent();
    }

    expect(collecteDesErreursGardees($compte))->toBe(ErreurNavigateur::RAPPORTS_PAR_MINUTE);

    $this->travel(61)->seconds();
    $this->postJson(route('erreurs-navigateur.store'), collecteDesErreursRapport('Boum de la minute suivante'))->assertNoContent();

    expect(collecteDesErreursGardees($compte))->toBe(ErreurNavigateur::RAPPORTS_PAR_MINUTE + 1);
});

it('n’écrit plus rien au-delà du plafond par jour, et répond toujours 204', function (): void {
    $compte = User::factory()->create();
    $this->actingAs($compte);

    foreach (range(1, ErreurNavigateur::RAPPORTS_PAR_JOUR) as $envoi) {
        if ($envoi % ErreurNavigateur::RAPPORTS_PAR_MINUTE === 1) {
            $this->travel(61)->seconds();
        }

        $this->postJson(route('erreurs-navigateur.store'), collecteDesErreursRapport("Boum {$envoi}"))->assertNoContent();
    }

    expect(collecteDesErreursGardees($compte))->toBe(ErreurNavigateur::RAPPORTS_PAR_JOUR);

    $this->travel(61)->seconds();
    $this->postJson(route('erreurs-navigateur.store'), collecteDesErreursRapport('Boum de trop'))->assertNoContent();

    expect(collecteDesErreursGardees($compte))->toBe(ErreurNavigateur::RAPPORTS_PAR_JOUR);
});

it('ne touche pas un autre compte, ni par le plafond ni par le regroupement du premier', function (): void {
    $premier = User::factory()->create();
    $second = User::factory()->create();

    foreach (range(1, ErreurNavigateur::RAPPORTS_PAR_MINUTE + 1) as $envoi) {
        $this->actingAs($premier)->postJson(route('erreurs-navigateur.store'), collecteDesErreursRapport("Boum {$envoi}"))->assertNoContent();
    }

    $this->actingAs($second)->postJson(route('erreurs-navigateur.store'), collecteDesErreursRapport('Boum 1'))->assertNoContent();
    $this->actingAs($second)->postJson(route('erreurs-navigateur.store'), collecteDesErreursRapport('Boum du second'))->assertNoContent();

    expect(collecteDesErreursGardees($premier))->toBe(ErreurNavigateur::RAPPORTS_PAR_MINUTE)
        ->and(collecteDesErreursGardees($second))->toBe(2);
});

it('refuse une ligne ou une colonne écrite autrement qu’en nombre entier, sans rien écrire, exception comprise', function (Closure $envoyer): void {
    $compte = User::factory()->create();
    $this->actingAs($compte);

    foreach (range(1, 3) as $envoi) {
        $envoyer($this)->assertUnprocessable();
    }

    expect(collecteDesErreursGardees($compte))->toBe(0)
        ->and(ExceptionEnregistree::query()->count())->toBe(0);
})->with([
    'formulaire, ligne en texte' => [static fn ($test) => $test->post(route('erreurs-navigateur.store'), [...collecteDesErreursRapport(), 'ligne' => '3'], ['Accept' => 'application/json'])],
    'JSON, ligne en texte' => [static fn ($test) => $test->postJson(route('erreurs-navigateur.store'), [...collecteDesErreursRapport(), 'ligne' => '3'])],
    'JSON, ligne en flottant' => [static fn ($test) => $test->call('POST', route('erreurs-navigateur.store'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], (string) json_encode([...collecteDesErreursRapport(), 'ligne' => 3.0], JSON_PRESERVE_ZERO_FRACTION))],
    'JSON, colonne en texte' => [static fn ($test) => $test->postJson(route('erreurs-navigateur.store'), [...collecteDesErreursRapport(), 'colonne' => '7'])],
]);

it('garde de nouveau une erreur qui se reproduit après avoir été effacée du panneau', function (): void {
    $compte = User::factory()->create();
    $this->actingAs($compte)->postJson(route('erreurs-navigateur.store'), collecteDesErreursRapport())->assertNoContent();
    $gardee = ErreurNavigateur::query()->where('user_id', $compte->id)->sole();

    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:ErreurNavigateur', 'View:ErreurNavigateur', 'Delete:ErreurNavigateur']), 'admin');
    Livewire::test(ListErreursNavigateur::class)->callAction(TestAction::make('delete')->table($gardee));
    expect(collecteDesErreursGardees($compte))->toBe(0);

    $this->travel(10)->minutes();
    $this->actingAs($compte)->postJson(route('erreurs-navigateur.store'), collecteDesErreursRapport())->assertNoContent();
    $this->actingAs($compte)->postJson(route('erreurs-navigateur.store'), collecteDesErreursRapport())->assertNoContent();

    expect(collecteDesErreursGardees($compte))->toBe(1);
});
