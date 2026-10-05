<?php

declare(strict_types=1);

use App\Actions\Tools\CreateWilksScoreAction;
use App\Models\User;
use App\Models\WilksScore;

use function Pest\Laravel\actingAs;

/*
 * Le polynôme de Wilks n'est défini que sur une plage de poids de corps :
 * 40 à 201,9 kg pour les hommes, 26,51 à 154,53 kg pour les femmes, les bornes
 * des implémentations de référence (#1959).
 *
 * Il s'appliquait sans borne, alors que la validation accepte 1 à 500, en kg ou
 * en lbs. Son dénominateur change de signe vers 13,5 et 283 kg chez l'homme,
 * vers 208 kg chez la femme : le score enregistré devenait négatif. Entre ces
 * seuils et la borne, le coefficient remontait au lieu de décroître.
 */

/**
 * Le score que l'application enregistre pour ce total.
 */
function scoreWilksEnregistre(float $poidsDeCorps, float $total, string $genre, string $unite = 'kg'): float
{
    return app(CreateWilksScoreAction::class)->execute(User::factory()->create(), [
        'body_weight' => $poidsDeCorps,
        'lifted_weight' => $total,
        'gender' => $genre,
        'unit' => $unite,
    ])->score;
}

it('donne hors de la plage le score de la borne la plus proche', function (string $genre, float $horsPlage, float $borne): void {
    $scoreALaBorne = scoreWilksEnregistre($borne, 500, $genre);

    expect($scoreALaBorne)->toBeGreaterThan(0.0)
        ->and(scoreWilksEnregistre($horsPlage, 500, $genre))->toBe($scoreALaBorne);
})->with([
    'homme de 1 kg' => ['male', 1.0, 40.0],
    'homme de 10 kg' => ['male', 10.0, 40.0],
    'homme de 13,5 kg, où le dénominateur s’annule' => ['male', 13.5, 40.0],
    'homme de 30 kg' => ['male', 30.0, 40.0],
    'homme de 250 kg' => ['male', 250.0, 201.9],
    'homme de 300 kg' => ['male', 300.0, 201.9],
    'homme de 500 kg' => ['male', 500.0, 201.9],
    'femme de 1 kg' => ['female', 1.0, 26.51],
    'femme de 20 kg' => ['female', 20.0, 26.51],
    'femme de 200 kg' => ['female', 200.0, 154.53],
    'femme de 250 kg' => ['female', 250.0, 154.53],
    'femme de 500 kg' => ['female', 500.0, 154.53],
]);

/*
 * 500 lbs valent 226,8 kg : au-delà de la borne féminine, et la validation
 * l'accepte. La borne s'applique au poids converti en kilos.
 */
it('borne le poids de corps une fois converti en kilos', function (): void {
    $enLivres = scoreWilksEnregistre(500, 1102.31, 'female', 'lbs');

    expect($enLivres)->toBeGreaterThan(0.0)
        ->and($enLivres)->toBe(scoreWilksEnregistre(154.53, 1102.31 / 2.20462, 'female'));
});

/**
 * Du plus léger au plus lourd, le score ne remonte jamais et reste positif.
 *
 * @param  list<float>  $poids  Des poids de corps croissants, en kilos.
 */
function scoreWilksDecroissantSur(string $genre, array $poids): void
{
    $precedent = null;

    foreach ($poids as $poidsDeCorps) {
        $score = scoreWilksEnregistre($poidsDeCorps, 500, $genre);

        expect($score)->toBeGreaterThan(0.0);

        if ($precedent !== null) {
            expect($score)->toBeLessThanOrEqual($precedent);
        }

        $precedent = $score;
    }
}

it('fait décroître le coefficient masculin jusqu’à la borne, puis le tient', function (): void {
    scoreWilksDecroissantSur('male', [150.0, 200.0, 201.9, 250.0, 283.0, 400.0, 500.0]);
});

it('fait décroître le coefficient féminin jusqu’à la borne, puis le tient', function (): void {
    scoreWilksDecroissantSur('female', [120.0, 154.53, 180.0, 208.0, 300.0, 500.0]);
});

it('enregistre un score positif pour un homme de 300 kg', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('tools.wilks.store'), [
            'body_weight' => 300,
            'lifted_weight' => 500,
            'gender' => 'male',
            'unit' => 'kg',
        ])
        ->assertRedirect();

    expect(WilksScore::query()->where('user_id', $user->id)->sole()->score)
        ->toBe(scoreWilksEnregistre(201.9, 500, 'male'))
        ->toBeGreaterThan(0.0);
});
