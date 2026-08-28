<?php

declare(strict_types=1);

use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * `allowedFilters('supplement_id')` passe en CHAINE devient un filtre PARTIEL
 * chez Spatie : `LOWER(supplement_id) LIKE '%1%'` sur un bigint.
 *
 * Ce n'etait pas qu'un cout — c'etait faux. Filtrer sur le complement 1
 * remontait aussi les journaux des complements 10, 21, 31.
 */
it('ne remonte que le complément demandé, pas ceux dont l’identifiant le contient', function (): void {
    $user = User::factory()->create();

    $premier = Supplement::factory()->create(['user_id' => $user->id]);
    $autres = Supplement::factory()->count(12)->create(['user_id' => $user->id]);
    $piege = $autres->firstWhere(fn (Supplement $s): bool => str_contains((string) $s->id, (string) $premier->id));

    SupplementLog::factory()->create(['user_id' => $user->id, 'supplement_id' => $premier->id]);

    if ($piege instanceof Supplement) {
        SupplementLog::factory()->create(['user_id' => $user->id, 'supplement_id' => $piege->id]);
    }

    $reponse = $this->actingAs($user)
        ->getJson(route('api.v1.supplement-logs.index').'?filter[supplement_id]='.$premier->id)
        ->assertOk();

    /** @var list<array<string, mixed>> $lignes */
    $lignes = $reponse->json('data') ?? [];

    $identifiants = [];

    foreach ($lignes as $ligne) {
        $identifiant = $ligne['supplement_id'] ?? null;

        if (is_numeric($identifiant)) {
            $identifiants[] = (int) $identifiant;
        }
    }

    $identifiants = array_values(array_unique($identifiants));

    expect($identifiants)->toBe([$premier->id]);
});
