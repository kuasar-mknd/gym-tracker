<?php

declare(strict_types=1);

/*
 * L'action etait executee par les tests du profil, qui envoient toujours un
 * `push_preferences` complet — un booleen pour chaque type coche. Le repli
 * `?? false` etait donc traverse sans jamais servir, et le remplacer par
 * `?? true` ne faisait echouer aucun test.
 *
 * Ce que cela laissait passer : une preference dont le push n'est pas nomme
 * s'enregistrerait comme ACTIVEE. C'est-a-dire une notification poussee sur le
 * telephone d'un utilisateur qui ne l'a pas demandee — le defaut doit tomber du
 * cote silencieux, et c'est le sens de la colonne (`DEFAULT '0'`).
 */

use App\Actions\Profile\UpdateNotificationPreferencesAction;
use App\Models\NotificationPreference;
use App\Models\User;

it('laisse le push eteint pour un type que la charge ne mentionne pas', function (): void {
    $user = User::factory()->create();

    // `push_preferences` couvre un type et pas l'autre, et `values` non plus :
    // c'est ce qu'envoie un formulaire dont une case n'a jamais ete touchee.
    app(UpdateNotificationPreferencesAction::class)->execute($user, [
        'preferences' => [
            'daily_reminder' => true,
            'weekly_summary' => true,
        ],
        'push_preferences' => [
            'weekly_summary' => true,
        ],
    ]);

    $quotidien = NotificationPreference::query()
        ->where('user_id', $user->id)
        ->where('type', 'daily_reminder')
        ->sole();

    expect($quotidien->is_enabled)->toBeTrue();
    expect($quotidien->is_push_enabled)->toBeFalse();
    expect($quotidien->value)->toBeNull();

    // Le type nomme, lui, garde bien son push : le repli ne s'applique pas a
    // tout le monde.
    $hebdomadaire = NotificationPreference::query()
        ->where('user_id', $user->id)
        ->where('type', 'weekly_summary')
        ->sole();

    expect($hebdomadaire->is_push_enabled)->toBeTrue();
});

it('laisse le push eteint quand la charge ne parle pas de push du tout', function (): void {
    $user = User::factory()->create();

    app(UpdateNotificationPreferencesAction::class)->execute($user, [
        'preferences' => ['daily_reminder' => true],
    ]);

    $quotidien = NotificationPreference::query()
        ->where('user_id', $user->id)
        ->where('type', 'daily_reminder')
        ->sole();

    expect($quotidien->is_push_enabled)->toBeFalse();
});

/*
 * Le `upsert` designe sa cle par `['user_id', 'type']`. Sur MySQL cette liste
 * n'entre pas dans le SQL genere — la grammaire s'en remet a l'index unique de
 * la table — donc en retirer un element ne change rien ici. Ce test pose tout
 * de meme le comportement attendu : un second envoi corrige la ligne, il n'en
 * ajoute pas une seconde.
 */
it('corrige la ligne existante au lieu d\'en creer une seconde', function (): void {
    $user = User::factory()->create();
    $action = app(UpdateNotificationPreferencesAction::class);

    $action->execute($user, [
        'preferences' => ['daily_reminder' => true],
        'push_preferences' => ['daily_reminder' => true],
        'days' => ['daily_reminder' => [1, 2]],
    ]);

    $action->execute($user, [
        'preferences' => ['daily_reminder' => false],
        'push_preferences' => ['daily_reminder' => false],
        'days' => ['daily_reminder' => [5, 3, 3]],
    ]);

    expect(NotificationPreference::query()->where('user_id', $user->id)->count())->toBe(1);

    $quotidien = NotificationPreference::query()
        ->where('user_id', $user->id)
        ->where('type', 'daily_reminder')
        ->sole();

    expect($quotidien->is_enabled)->toBeFalse();
    expect($quotidien->is_push_enabled)->toBeFalse();
    expect($quotidien->days)->toBe([3, 5]);
});

it('n\'ecrit rien quand aucune preference n\'est envoyee', function (): void {
    $user = User::factory()->create();

    app(UpdateNotificationPreferencesAction::class)->execute($user, [
        'preferences' => [],
    ]);

    expect(NotificationPreference::query()->where('user_id', $user->id)->count())->toBe(0);
});

/*
 * Les trois colonnes mises à jour par l'upsert doivent l'être vraiment :
 * une ligne existante change d'état, de push et de jours.
 */
it('met à jour l’état, le push et les jours d’une ligne existante', function (): void {
    $user = User::factory()->create();
    $action = app(UpdateNotificationPreferencesAction::class);

    $action->execute($user, [
        'preferences' => ['daily_reminder' => false],
        'push_preferences' => ['daily_reminder' => false],
        'days' => ['daily_reminder' => [1]],
    ]);
    $action->execute($user, [
        'preferences' => ['daily_reminder' => true],
        'push_preferences' => ['daily_reminder' => true],
        'days' => ['daily_reminder' => [4, 2]],
    ]);

    $quotidien = NotificationPreference::where('user_id', $user->id)->where('type', 'daily_reminder')->sole();

    expect($quotidien->is_enabled)->toBeTrue()
        ->and($quotidien->is_push_enabled)->toBeTrue()
        ->and($quotidien->days)->toBe([2, 4]);
});
