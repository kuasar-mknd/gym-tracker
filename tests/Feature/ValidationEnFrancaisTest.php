<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutTemplate;
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

/**
 * Tous les messages rendus par la dernière requête refusée.
 *
 * @return list<string>
 */
function validationEnFrancaisTousLesMessages(): array
{
    /** @var \Illuminate\Support\ViewErrorBag $erreurs */
    $erreurs = session('errors');

    /** @var list<string> $messages */
    $messages = $erreurs->all();

    return $messages;
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

    expect(validationEnFrancaisMessage('value'))->toBe('Une mesure ne dépasse pas 999,99.')
        ->and(validationEnFrancaisMessage('measured_at'))->toBe('La date de mesure ne peut pas être dans le futur.');
});

it('écrit à la française la borne décimale du poids d’un disque', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('plates.store'), ['weight' => 0.05, 'quantity' => 1])
        ->assertSessionHasErrors('weight');

    expect(validationEnFrancaisMessage('weight'))->toBe('Un disque pèse au moins 0,1 kg.');
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

it('demande en français ce qu’il manque à un objectif, sans la valeur brute de son type', function (array $objectif, string $champ, string $message): void {
    $this->actingAs(User::factory()->create())
        ->post(route('goals.store'), ['title' => 'Objectif', 'target_value' => 100, ...$objectif])
        ->assertSessionHasErrors($champ);

    expect(validationEnFrancaisMessage($champ))->toBe($message);
})->with([
    'force sans exercice' => [['type' => 'weight', 'exercise_id' => ''], 'exercise_id', "Choisis l'exercice de cet objectif."],
    'volume sans exercice' => [['type' => 'volume'], 'exercise_id', "Choisis l'exercice de cet objectif."],
    'mensuration sans mesure' => [['type' => 'measurement', 'measurement_type' => ''], 'measurement_type', 'Choisis la mensuration suivie.'],
]);

it('dit en français que les types d’envoi push forment une liste', function (): void {
    $this->actingAs(User::factory()->create())
        ->patchJson(route('profile.push-preferences.update'), ['types' => ['records' => 'personal_record']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['types' => 'Le champ types doit être une liste.']);
});

/*
 * Le modèle de séance vérifie ses exercices hors des règles, en une requête,
 * et composait son message à la main avec un nom anglais : un exercice
 * supprimé depuis un autre onglet affichait « Le champ exercise id
 * sélectionné est invalide. » sous l'exercice (#1975).
 */
it('nomme en français l’exercice refusé d’un modèle de séance, à la création comme à la modification', function (string $geste): void {
    $utilisateur = User::factory()->create();
    $exerciceDAutrui = Exercise::factory()->create(['user_id' => User::factory()->create()->id]);
    $donnees = [
        'name' => 'Modèle',
        'exercises' => [['id' => $exerciceDAutrui->id, 'sets' => [['reps' => 10, 'weight' => 20, 'is_warmup' => false]]]],
    ];

    $requete = $this->actingAs($utilisateur);
    $reponse = $geste === 'création'
        ? $requete->post(route('templates.store'), $donnees)
        : $requete->put(route('templates.update', WorkoutTemplate::factory()->create(['user_id' => $utilisateur->id])), $donnees);

    $reponse->assertSessionHasErrors('exercises.0.id');

    $message = validationEnFrancaisMessage('exercises.0.id');

    expect($message)->toBe('Le champ exercice sélectionné est invalide.');
    expect($message)->not->toContain('exercise id');
})->with(['création', 'modification']);

it('ne laisse dans aucun de ces messages ni nom de colonne ni « today »', function (): void {
    $utilisateur = User::factory()->create();

    $this->actingAs($utilisateur)->post(route('goals.store'), ['deadline' => '2026-10-04', 'target_value' => -1]);
    $messages = validationEnFrancaisTousLesMessages();
    $this->actingAs($utilisateur)->post(route('body-parts.store'), ['value' => 5000, 'measured_at' => '2026-10-06', 'unit' => 'kg']);
    $messages = [...$messages, ...validationEnFrancaisTousLesMessages()];
    $this->actingAs($utilisateur)->post(route('supplements.store'), ['servings_remaining' => -1, 'low_stock_threshold' => -1]);
    $messages = [...$messages, ...validationEnFrancaisTousLesMessages()];

    expect($messages)->not->toBeEmpty();

    foreach ($messages as $message) {
        expect($message)->not->toMatch('/\b(today|deadline|target[ _]value|measured[ _]at|servings[ _]remaining|low[ _]stock[ _]threshold|value|unit|part)\b/iu');
    }
});
