<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * Les messages de validation citaient la colonne et recopiaient le mot
 * anglais des règles relatives à aujourd'hui (#1975) :
 *
 *   « Le champ deadline doit être une date postérieure au today. »
 *   « La valeur de value ne peut pas être supérieure à 999.99. »
 *   « Le champ measured at doit être une date antérieure ou égale au today. »
 *   « La valeur de servings remaining doit être au moins de 0. »
 *
 * Les trois cas sont rejoués ici par de vraies requêtes, et chaque message
 * rendu est lu en entier.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 10:00:00');
});

/**
 * Le message rendu pour un champ, après une requête refusée.
 */
function validationEnFrancaisMessage(string $champ): string
{
    /** @var \Illuminate\Support\ViewErrorBag $erreurs */
    $erreurs = session('errors');

    return (string) $erreurs->first($champ);
}

it('dit qu’une échéance passée doit être à venir, sans « deadline » ni « today »', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('goals.store'), [
            'title' => 'Tour de taille',
            'type' => 'measurement',
            'measurement_type' => 'weight',
            'target_value' => 80,
            'deadline' => '2026-10-04',
        ])
        ->assertSessionHasErrors('deadline');

    expect(validationEnFrancaisMessage('deadline'))->toBe("L'échéance doit être une date à venir.");
});

it('nomme la mesure et sa date en français', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('body-parts.store'), [
            'part' => 'Waist',
            'value' => 5000,
            'unit' => 'cm',
            'measured_at' => '2026-10-06',
        ])
        ->assertSessionHasErrors(['value', 'measured_at']);

    expect(validationEnFrancaisMessage('value'))->toBe('La valeur de mesure ne peut pas être supérieure à 999.99.')
        ->and(validationEnFrancaisMessage('measured_at'))->toBe('La date de mesure ne peut pas être dans le futur.');
});

it('nomme les doses restantes et le seuil de stock bas en français', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('supplements.store'), [
            'name' => 'Créatine',
            'servings_remaining' => -1,
            'low_stock_threshold' => -1,
        ])
        ->assertSessionHasErrors(['servings_remaining', 'low_stock_threshold']);

    expect(validationEnFrancaisMessage('servings_remaining'))->toBe('La valeur de doses restantes doit être au moins de 0.')
        ->and(validationEnFrancaisMessage('low_stock_threshold'))->toBe('La valeur de seuil de stock bas doit être au moins de 0.');
});

it('ne laisse dans aucun de ces messages ni nom de colonne ni « today »', function (): void {
    $utilisateur = User::factory()->create();

    $this->actingAs($utilisateur)->post(route('goals.store'), ['deadline' => '2026-10-04', 'target_value' => -1]);
    $messages = session('errors')->all();
    $this->actingAs($utilisateur)->post(route('body-parts.store'), ['value' => 5000, 'measured_at' => '2026-10-06', 'unit' => 'kg']);
    $messages = [...$messages, ...session('errors')->all()];
    $this->actingAs($utilisateur)->post(route('supplements.store'), ['servings_remaining' => -1, 'low_stock_threshold' => -1]);
    $messages = [...$messages, ...session('errors')->all()];

    expect($messages)->not->toBeEmpty();

    foreach ($messages as $message) {
        expect($message)->not->toMatch('/\b(today|deadline|target[ _]value|measured[ _]at|servings[ _]remaining|low[ _]stock[ _]threshold|value|unit|part)\b/iu');
    }
});
