<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WarmupPreference;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * `User::warmupPreference()` est un `hasOne` : il n'en existe qu'une par compte.
 *
 * L'API faisait pourtant un `create()` nu — chaque POST empilait une ligne que
 * la relation ne lisait jamais, et que `index()` rendait quand meme.
 */
it('ne garde qu’une préférence par compte, quel que soit le nombre d’envois', function (): void {
    $user = User::factory()->create();

    $charge = [
        'bar_weight' => 20,
        'rounding_increment' => 2.5,
        'steps' => [['percent' => 50, 'reps' => 10, 'label' => 'Barre vide']],
    ];

    $this->actingAs($user)->postJson(route('api.v1.warmup-preferences.store'), $charge)->assertCreated();
    $this->actingAs($user)->postJson(route('api.v1.warmup-preferences.store'), [...$charge, 'bar_weight' => 25])->assertCreated();

    expect(WarmupPreference::where('user_id', $user->id)->count())->toBe(1)
        ->and((float) $user->warmupPreference?->bar_weight)->toBe(25.0);
});
