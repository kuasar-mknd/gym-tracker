<?php

declare(strict_types=1);

use App\Actions\CreateUserAction;
use App\Actions\CreerLesPlaquesParDefautAction;
use App\Models\Plate;
use App\Models\User;

/**
 * Sans plaques, le calculateur ouvrait sur « Impossible de charger ce poids » —
 * un échec comme premier contact, alors que rien ne disait qu'il fallait
 * d'abord déclarer son matériel (#1799).
 */
it('donne le jeu olympique à un compte neuf', function (): void {
    $utilisateur = app(CreateUserAction::class)->execute([
        'name' => 'Neuf',
        'email' => 'neuf@example.test',
        'password' => 'motdepasse123',
    ]);

    $plaques = $utilisateur->plates()->orderByDesc('weight')->get();

    expect($plaques->map(static fn (Plate $plaque): int => $plaque->quantity)->all())->each->toBe(2)
        ->and($plaques->map(static fn (Plate $plaque): float => (float) $plaque->weight)->all())
        ->toBe([25.0, 20.0, 15.0, 10.0, 5.0, 2.5, 1.25]);
});

it('charge cent kilos sur une barre de vingt avec ce jeu', function (): void {
    $utilisateur = User::factory()->create();
    app(CreerLesPlaquesParDefautAction::class)->execute($utilisateur);

    /*
     * Le calcul lui-même vit dans le navigateur ; ce qu'on prouve ici est qu'il
     * a de quoi travailler : 40 kg par côté avec les disques disponibles.
     */
    $parCote = 40.0;
    $disponibles = $utilisateur->plates()->orderByDesc('weight')->get()
        ->flatMap(fn (Plate $plaque): array => array_fill(0, (int) ($plaque->quantity / 2), (float) $plaque->weight));

    $reste = $parCote;

    foreach ($disponibles as $poids) {
        if ($poids <= $reste) {
            $reste -= $poids;
        }
    }

    expect($reste)->toBe(0.0);
});

it('ne double pas les plaques de qui en a déjà', function (): void {
    $utilisateur = User::factory()->create();
    Plate::factory()->for($utilisateur)->create(['weight' => 20, 'quantity' => 4]);

    app(CreerLesPlaquesParDefautAction::class)->execute($utilisateur);

    expect($utilisateur->plates()->count())->toBe(1);
});
