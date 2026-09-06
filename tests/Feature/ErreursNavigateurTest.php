<?php

declare(strict_types=1);

use App\Filament\Resources\ErreursNavigateur\ErreurNavigateurResource;
use App\Models\Admin;
use App\Models\ErreurNavigateur;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;

/**
 * Les erreurs du navigateur, rapportées par la page et lues dans le panneau :
 * ce que Sentry recevait du client, gardé ici (#1511).
 */
it('garde une erreur rapportée par un utilisateur connecté, avec son empreinte', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('erreurs-navigateur.store'), [
            'type' => 'error',
            'message' => "Cannot read properties of undefined (reading 'id')",
            'source' => 'https://gym.example/build/assets/main-abc.js',
            'ligne' => 12,
            'colonne' => 345,
            'pile' => "TypeError: Cannot read properties\n    at main-abc.js:12:345",
            'url' => 'https://gym.example/workouts/3',
            'agent' => 'Mozilla/5.0',
        ])
        ->assertNoContent();

    $erreur = ErreurNavigateur::query()->sole();

    expect($erreur->user_id)->toBe($user->id)
        ->and($erreur->type)->toBe('error')
        ->and($erreur->ligne)->toBe(12)
        ->and($erreur->colonne)->toBe(345)
        ->and($erreur->empreinte)->toBe(ErreurNavigateur::empreinteDe('error', "Cannot read properties of undefined (reading 'id')", 'https://gym.example/build/assets/main-abc.js', 12))
        ->and($erreur->user->is($user))->toBeTrue();
});

it('refuse un visiteur et une charge qui ne dit pas ce qu’elle est', function (): void {
    $this->postJson(route('erreurs-navigateur.store'), ['type' => 'error', 'message' => 'x', 'url' => '/'])
        ->assertUnauthorized();

    $this->actingAs(User::factory()->create())
        ->postJson(route('erreurs-navigateur.store'), ['type' => 'autre', 'message' => '', 'url' => '/'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type', 'message']);

    expect(ErreurNavigateur::query()->count())->toBe(0);
});

it('ouvre la liste au super administrateur, sans permission Shield', function (): void {
    $superAdmin = Admin::factory()->create();
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'admin'));

    $this->actingAs($superAdmin, 'admin')
        ->get(ErreurNavigateurResource::getUrl('index', panel: 'admin'))
        ->assertOk();
});

it('ouvre la liste à qui porte la permission Shield', function (): void {
    ErreurNavigateur::query()->create([
        'type' => 'vue',
        'message' => 'Rendu impossible',
        'source' => 'render function',
        'url' => 'https://gym.example/stats',
        'empreinte' => ErreurNavigateur::empreinteDe('vue', 'Rendu impossible', 'render function', null),
    ]);

    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:ErreurNavigateur']), 'admin')
        ->get(ErreurNavigateurResource::getUrl('index', panel: 'admin'))
        ->assertOk()
        ->assertSee('Rendu impossible');
});

it('se refuse à un administrateur ordinaire', function (): void {
    $this->actingAs(Admin::factory()->create(), 'admin')
        ->get(ErreurNavigateurResource::getUrl('index', panel: 'admin'))
        ->assertForbidden();
});

it('oublie les erreurs de plus de trente jours, par le planificateur', function (): void {
    $vieille = ErreurNavigateur::query()->create([
        'type' => 'error', 'message' => 'vieille', 'url' => '/', 'empreinte' => ErreurNavigateur::empreinteDe('error', 'vieille', null, null),
    ]);
    $vieille->forceFill(['created_at' => now()->subDays(31)])->save();
    ErreurNavigateur::query()->create([
        'type' => 'error', 'message' => 'fraîche', 'url' => '/', 'empreinte' => ErreurNavigateur::empreinteDe('error', 'fraîche', null, null),
    ]);

    $this->artisan('model:prune', ['--model' => [ErreurNavigateur::class]])->assertSuccessful();

    expect(ErreurNavigateur::query()->pluck('message')->all())->toBe(['fraîche']);

    $commandes = collect(app(Schedule::class)->events())
        ->map(fn ($evenement): string => (string) $evenement->command)
        ->filter(fn (string $ligne): bool => str_contains($ligne, 'model:prune'))
        ->all();

    expect(implode(' ', $commandes))->toContain('ErreurNavigateur');
});
