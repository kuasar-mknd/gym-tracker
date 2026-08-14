<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

beforeEach(function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('User')), 'admin');
});

it('liste les utilisateurs existants dans la table', function (): void {
    $users = User::factory()->count(3)->create();
    $first = $users->firstOrFail();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords($users)
        ->assertSee($first->name)
        ->assertSee($first->email);
});

it('affiche les colonnes de streak avec la valeur réelle du modèle', function (): void {
    $user = User::factory()->create([
        'name' => 'Streaky McStreak',
        'current_streak' => 12,
        'longest_streak' => 34,
        'default_rest_time' => 145,
    ]);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$user])
        ->assertTableColumnStateSet('current_streak', 12, $user)
        ->assertTableColumnStateSet('longest_streak', 34, $user)
        ->assertTableColumnStateSet('default_rest_time', 145, $user);
});

it('ne montre que les utilisateurs correspondant à la recherche', function (): void {
    $matching = User::factory()->create(['name' => 'Alphonse Recherchable']);
    $other = User::factory()->create(['name' => 'Bertrand Invisible']);

    Livewire::test(ListUsers::class)
        ->searchTable('Alphonse Recherchable')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

it('trie les utilisateurs par streak courant', function (): void {
    $low = User::factory()->create(['current_streak' => 1]);
    $high = User::factory()->create(['current_streak' => 99]);
    $mid = User::factory()->create(['current_streak' => 50]);

    Livewire::test(ListUsers::class)
        ->sortTable('current_streak', 'desc')
        ->assertCanSeeTableRecords([$high, $mid, $low], inOrder: true);
});

it("affiche les emails sur la page d'index à un admin porteur de ViewAny:User", function (): void {
    User::factory()->create(['email' => 'confidentiel@example.com']);

    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:User']), 'admin')
        ->get(UserResource::getUrl('index'))
        ->assertOk()
        ->assertSee('confidentiel@example.com');
});

it('ne laisse fuiter aucun email à un admin sans la permission ViewAny:User', function (): void {
    User::factory()->create(['email' => 'confidentiel@example.com']);

    $this->actingAs(FilamentAdminPanel::admin([]), 'admin')
        ->get(UserResource::getUrl('index'))
        ->assertDontSee('confidentiel@example.com')
        ->assertForbidden();
});

it('refuse création, édition et suppression à un admin sans les permissions correspondantes', function (): void {
    $admin = FilamentAdminPanel::admin(['ViewAny:User']);
    $this->actingAs($admin, 'admin');

    // La cible doit porter un id différent de celui de l'admin : UserPolicy
    // compare les deux identifiants malgré les guards distincts (test dédié plus bas).
    $target = User::factory()->create(['id' => $admin->getKey() + 1]);

    expect(UserResource::canViewAny())->toBeTrue()
        ->and(UserResource::canCreate())->toBeFalse()
        ->and(UserResource::canEdit($target))->toBeFalse()
        ->and(UserResource::canDelete($target))->toBeFalse();
});

it("sert le formulaire d'édition prérempli à un admin porteur de Update:User", function (): void {
    $granted = FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('User'));
    $target = User::factory()->create([
        'id' => $granted->getKey() + 1,
        'email' => 'cible@example.com',
    ]);

    $this->actingAs($granted, 'admin')
        ->get(UserResource::getUrl('edit', ['record' => $target]))
        ->assertOk()
        ->assertSee('cible@example.com');
});

it("cache le formulaire d'édition à un admin sans la permission Update:User", function (): void {
    $denied = FilamentAdminPanel::admin(['ViewAny:User', 'View:User']);

    // Id de la cible distinct de celui de l'admin, sinon UserPolicy laisse
    // passer par simple collision d'identifiants entre les deux tables.
    $target = User::factory()->create([
        'id' => $denied->getKey() + 1,
        'email' => 'cible@example.com',
    ]);

    $this->actingAs($denied, 'admin')
        ->get(UserResource::getUrl('edit', ['record' => $target]))
        ->assertDontSee('cible@example.com')
        ->assertForbidden();
});

it("charge les valeurs de l'utilisateur dans le formulaire d'édition", function (): void {
    $user = User::factory()->create([
        'name' => 'Ancien Nom',
        'email' => 'ancien@example.com',
        'default_rest_time' => 75,
        'current_streak' => 4,
        'longest_streak' => 9,
    ]);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('email')
        ->assertFormFieldExists('default_rest_time')
        ->assertFormFieldExists('current_streak')
        ->assertFormFieldExists('longest_streak')
        ->assertFormSet([
            'name' => 'Ancien Nom',
            'email' => 'ancien@example.com',
            'default_rest_time' => '75',
            'current_streak' => '4',
            'longest_streak' => '9',
        ]);
});

it('refuse la création sans nom et avec un email invalide', function (): void {
    $before = User::query()->count();

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => null,
            'email' => 'pas-un-email',
            'default_rest_time' => 90,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'required',
            'email' => 'email',
        ]);

    expect(User::query()->count())->toBe($before);
});

it('refuse la création sans temps de repos par défaut', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Sans Repos',
            'email' => 'sans-repos@example.com',
            'default_rest_time' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['default_rest_time' => 'required']);

    expect(User::query()->where('email', 'sans-repos@example.com')->exists())->toBeFalse();
});

/**
 * Enregistrer une édition ne doit pas effacer le mot de passe de la cible.
 *
 * Le champ mot de passe n'avait aucun garde de déshydratation. Filament remplit
 * le formulaire depuis `attributesToArray()`, qui exclut `$hidden` — et
 * `password` y est. La clé étant absente des données de remplissage, le champ
 * est reposé à `null`, puis déshydraté, puis écrit : `password` est dans
 * `$fillable`, le cast `hashed` rend `null` pour `null`, et la colonne est
 * nullable. `UPDATE users SET password = NULL`, avec une notification de succès
 * et un compte qui ne peut plus se connecter (#1438).
 *
 * Le mode strict est coupé ici parce que c'est la production qu'il faut
 * reproduire. Hors production, `Model::shouldBeStrict()` fait lever sur les
 * autres champs du même formulaire — `email_verified_at`, `provider`,
 * `provider_id`, absents de `$fillable` — donc l'écriture échoue bruyamment
 * AVANT d'atteindre le mot de passe. C'est ce qui masquait le défaut partout où
 * on l'aurait regardé, et pourquoi il ne se voit qu'en production (#1352).
 */
it('laisse le mot de passe intact quand on enregistre une édition', function (): void {
    Illuminate\Database\Eloquent\Model::preventSilentlyDiscardingAttributes(false);

    $user = User::factory()->create(['name' => 'Ancien Nom']);
    $hash = $user->password;

    expect($hash)->not->toBeNull();

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['name' => 'Nouveau Nom'])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    expect($user->name)->toBe('Nouveau Nom');
    expect($user->password)->toBe($hash);
});

/**
 * Et un mot de passe réellement saisi doit, lui, être écrit — sans quoi le
 * garde ci-dessus se contenterait de rendre le champ inerte.
 */
it('écrit le mot de passe quand on en saisit un', function (): void {
    Illuminate\Database\Eloquent\Model::preventSilentlyDiscardingAttributes(false);

    $user = User::factory()->create();
    $ancien = $user->password;

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['password' => 'un-nouveau-mot-de-passe'])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    $nouveau = $user->password;

    expect($nouveau)->not->toBe($ancien);
    expect($nouveau)->not->toBeNull();
    expect(Illuminate\Support\Facades\Hash::check('un-nouveau-mot-de-passe', (string) $nouveau))->toBeTrue();
});

/**
 * Un compte cree sans mot de passe ne peut pas se connecter, et rien ne le
 * signalait : le champ n'etait pas requis, et `users.password` est nullable.
 * C'est le pendant a la creation du defaut d'edition ci-dessus (#1438).
 */
it('refuse la création d’un compte sans mot de passe', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Sans Mot De Passe',
            'email' => 'sans-mdp@example.com',
            'default_rest_time' => 90,
        ])
        ->call('create')
        ->assertHasFormErrors(['password' => 'required']);

    expect(User::query()->where('email', 'sans-mdp@example.com')->exists())->toBeFalse();
});
