<?php

declare(strict_types=1);

use App\Models\Supplement;
use App\Models\User;

/*
 * Le propriétaire d'un supplément est fixé à sa création : `latestLog()` ne
 * filtre pas sur le compte, et un changement de propriétaire montrerait la
 * dernière prise de l'ancien.
 */
it('refuse de changer le propriétaire d’un supplément enregistré', function (Closure $tentative): void {
    $avant = User::factory()->create();
    $apres = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $avant->id]);

    expect(fn (): mixed => $tentative($supplement, $apres))
        ->toThrow(LogicException::class, 'ne change pas après sa création');

    expect(Supplement::query()->findOrFail($supplement->id)->user_id)->toBe($avant->id);
})->with([
    'par affectation' => function (Supplement $supplement, User $compte): bool {
        $supplement->user_id = $compte->id;

        return $supplement->save();
    },
    'par la relation' => fn (Supplement $supplement, User $compte): bool => $supplement->user()->associate($compte)->save(),
]);

it('laisse modifier le reste d’un supplément', function (): void {
    $supplement = Supplement::factory()->create();

    $supplement->update(['name' => 'Autre nom']);

    expect($supplement->fresh()?->name)->toBe('Autre nom');
});
