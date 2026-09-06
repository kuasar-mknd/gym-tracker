<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\ExceptionEnregistree;
use BezhanSalleh\FilamentExceptions\Resources\ExceptionResource;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;

/**
 * Les exceptions du serveur, lues dans le panneau plutôt que chez un tiers :
 * premier pas de #1511. Ce qu'une requête porte de secret n'entre pas en base.
 */
it('garde une exception rapportée, avec son type, son message et sa requête', function (): void {
    Route::post('/_boum', function (): never {
        throw new RuntimeException('boum');
    });

    $this->post('/_boum', ['name' => 'Rowing', 'password' => 'secret'])->assertStatus(500);

    $exception = ExceptionEnregistree::query()->sole();

    expect($exception->type)->toBe(RuntimeException::class)
        ->and($exception->message)->toBe('boum')
        ->and($exception->method)->toBe('POST')
        ->and($exception->path)->toBe('_boum');
});

it('masque les champs sensibles et ne garde aucun cookie', function (): void {
    Route::post('/_boum', function (): never {
        throw new RuntimeException('boum');
    });

    $this->withUnencryptedCookie('session', 'jeton')
        ->withHeader('Authorization', 'Bearer x')
        ->post('/_boum', ['name' => 'Rowing', 'password' => 'secret', 'profil' => ['token' => 'abc', 'age' => 30]])
        ->assertStatus(500);

    $exception = ExceptionEnregistree::query()->sole();

    // Par valeur : la colonne JSON ne garde pas l'ordre des clefs.
    expect($exception->body)->toEqual(['name' => 'Rowing', 'password' => '[masqué]', 'profil' => ['token' => '[masqué]', 'age' => 30]])
        ->and($exception->cookies)->toBeNull()
        ->and($exception->headers)->not->toBeNull();

    foreach ((array) $exception->headers as $nom => $valeur) {
        if (strtolower((string) $nom) === 'authorization') {
            expect($valeur)->toBe('[masqué]');
        }
    }
});

it('ouvre la liste au super administrateur, sans permission Shield', function (): void {
    $superAdmin = Admin::factory()->create();
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'admin'));

    $this->actingAs($superAdmin, 'admin')
        ->get(ExceptionResource::getUrl('index', panel: 'admin'))
        ->assertOk();
});

it('ouvre la liste à qui porte la permission Shield', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:ExceptionEnregistree']), 'admin')
        ->get(ExceptionResource::getUrl('index', panel: 'admin'))
        ->assertOk();
});

it('se refuse à un administrateur ordinaire', function (): void {
    $this->actingAs(Admin::factory()->create(), 'admin')
        ->get(ExceptionResource::getUrl('index', panel: 'admin'))
        ->assertForbidden();
});

it('purge les exceptions de plus de trente jours par le planificateur', function (): void {
    $commandes = collect(app(Schedule::class)->events())
        ->map(fn ($evenement): string => (string) $evenement->command)
        ->filter(fn (string $ligne): bool => str_contains($ligne, 'model:prune'))
        ->all();

    expect($commandes)->not->toBeEmpty();
    expect(implode(' ', $commandes))->toContain('ExceptionEnregistree');
});
