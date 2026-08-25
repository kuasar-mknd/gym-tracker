<?php

declare(strict_types=1);

/*
 * Prendre un complement etait execute par les tests du controleur sans etre
 * verifie : la quantite journalisee et la borne du stock passaient toutes deux
 * a la trappe.
 */

use App\Actions\Supplements\ConsumeSupplementAction;
use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Support\Carbon;

it('journalise une portion et retire une portion du stock', function (): void {
    // Horloge figee loin d'un changement d'heure : `consumed_at` est compare a
    // une date posee, pas a une seconde `now()` evaluee apres coup.
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    $supplement = Supplement::factory()->create([
        'user_id' => $user->id,
        'servings_remaining' => 5,
    ]);

    /*
     * `supplement_logs.quantity` vaut 1 par defaut en base : si l'action cesse
     * d'ecrire la cle, la ligne inseree est identique et aucune assertion sur
     * la base ne peut le voir. On observe donc l'attribut POSE sur le modele,
     * avant l'insertion — le seul endroit ou l'oubli se distingue du defaut.
     * L'ecouteur est enregistre sur le repartiteur d'evenements du test, qui
     * est neuf a chaque test : il ne fuit pas sur les suivants.
     */
    $quantiteEcrite = null;
    SupplementLog::creating(function (SupplementLog $log) use (&$quantiteEcrite): void {
        $quantiteEcrite = $log->getAttributes()['quantity'] ?? null;
    });

    app(ConsumeSupplementAction::class)->execute($user, $supplement);

    expect($quantiteEcrite)->toBe(1);

    $this->assertDatabaseHas(SupplementLog::class, [
        'user_id' => $user->id,
        'supplement_id' => $supplement->id,
        'quantity' => 1,
        'consumed_at' => '2026-06-15 12:00:00',
    ]);

    expect($supplement->refresh()->servings_remaining)->toBe(4);
});

it('retire la derniere portion du stock', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create([
        'user_id' => $user->id,
        // Exactement une portion : c'est la borne. Avec un stock quelconque,
        // remplacer `> 0` par `> 1` reste invisible ; ici la derniere portion
        // resterait au stock apres avoir ete consommee.
        'servings_remaining' => 1,
    ]);

    app(ConsumeSupplementAction::class)->execute($user, $supplement);

    expect($supplement->refresh()->servings_remaining)->toBe(0);
    expect(SupplementLog::where('supplement_id', $supplement->id)->count())->toBe(1);
});

it('journalise la prise sans faire passer le stock sous zero', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create([
        'user_id' => $user->id,
        'servings_remaining' => 0,
    ]);

    app(ConsumeSupplementAction::class)->execute($user, $supplement);

    expect($supplement->refresh()->servings_remaining)->toBe(0);
    expect(SupplementLog::where('supplement_id', $supplement->id)->count())->toBe(1);
});
