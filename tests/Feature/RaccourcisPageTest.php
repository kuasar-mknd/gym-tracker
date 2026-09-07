<?php

declare(strict_types=1);

use App\Models\User;

/**
 * L'application avait un raccourci — ⌘K dans la bibliothèque — annoncé par une
 * pastille et listé nulle part (#1817). Une page le dit, et `?` l'ouvre.
 */
it('sert la page des raccourcis à qui est connecté', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('shortcuts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Raccourcis'));
});

it('la refuse à qui ne l’est pas', function (): void {
    $this->get(route('shortcuts.index'))->assertRedirect(route('login'));
});
