<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use Tests\Support\FilamentAdminPanel;

/**
 * Le journal d'audit n'avait aucun lecteur : huit modèles y écrivaient, et
 * personne ne pouvait le consulter. Il se lit désormais dans le panneau, en
 * lecture seule, derrière sa propre permission Shield.
 */
it('liste les entrées d audit à un admin qui en a la permission', function (): void {
    $admin = FilamentAdminPanel::admin(['ViewAny:ActivityLog']);
    $user = User::factory()->create(['name' => 'Personne Auditée']);
    $user->update(['name' => 'Personne Renommée']);

    $this->actingAs($admin, 'admin')
        ->get('/backoffice/activity-logs')
        ->assertOk()
        ->assertSee("Journal d'audit")
        ->assertSee('updated');
});

it('refuse le journal à un admin sans la permission', function (): void {
    $admin = FilamentAdminPanel::admin(['ViewAny:User']);

    $this->actingAs($admin, 'admin')
        ->get('/backoffice/activity-logs')
        ->assertForbidden();
});

it('ne journalise plus les modèles métier, seulement les comptes', function (): void {
    $user = User::factory()->create();
    $admin = Admin::factory()->create();
    $avant = \App\Models\ActivityLog::query()->count();

    $exercise = \App\Models\Exercise::factory()->create(['user_id' => $user->id]);
    $workout = \App\Models\Workout::factory()->create(['user_id' => $user->id]);
    \App\Models\WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
    \App\Models\Goal::factory()->create(['user_id' => $user->id]);
    \App\Models\Supplement::factory()->create(['user_id' => $user->id]);
    \App\Models\Achievement::factory()->create();

    expect(\App\Models\ActivityLog::query()->count())->toBe($avant);

    $user->update(['name' => 'Nom Modifié']);
    $admin->update(['name' => 'Admin Modifié']);

    expect(\App\Models\ActivityLog::query()->count())->toBe($avant + 2);
});
