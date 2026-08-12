<?php

declare(strict_types=1);

use App\Filament\Resources\Supplements\Pages\CreateSupplement;
use App\Filament\Resources\Supplements\Pages\EditSupplement;
use App\Filament\Resources\Supplements\Pages\ListSupplements;
use App\Filament\Resources\Supplements\SupplementResource;
use App\Models\Supplement;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

beforeEach(function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Supplement')), 'admin');
});

it('liste les suppléments avec leur propriétaire et leur stock', function (): void {
    $owner = User::factory()->create(['name' => 'Camille Consommatrice']);
    $supplement = Supplement::factory()->create([
        'user_id' => $owner->getKey(),
        'name' => 'Créatine monohydrate',
        'brand' => 'MarqueTest',
        'dosage' => '5g',
        'servings_remaining' => 42,
        'low_stock_threshold' => 8,
    ]);

    Livewire::test(ListSupplements::class)
        ->assertCanSeeTableRecords([$supplement])
        ->assertTableColumnStateSet('user.name', 'Camille Consommatrice', $supplement)
        ->assertTableColumnStateSet('name', 'Créatine monohydrate', $supplement)
        ->assertTableColumnStateSet('brand', 'MarqueTest', $supplement)
        ->assertTableColumnStateSet('servings_remaining', 42, $supplement)
        ->assertTableColumnStateSet('low_stock_threshold', 8, $supplement);
});

it('trie les suppléments par stock restant', function (): void {
    $empty = Supplement::factory()->create(['servings_remaining' => 1]);
    $full = Supplement::factory()->create(['servings_remaining' => 500]);
    $mid = Supplement::factory()->create(['servings_remaining' => 250]);

    Livewire::test(ListSupplements::class)
        ->sortTable('servings_remaining', 'desc')
        ->assertCanSeeTableRecords([$full, $mid, $empty], inOrder: true);
});

it('crée un supplément rattaché au bon utilisateur', function (): void {
    $owner = User::factory()->create();

    Livewire::test(CreateSupplement::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'name' => 'Whey isolate',
            'brand' => 'MarqueY',
            'dosage' => '30g',
            'servings_remaining' => 60,
            'low_stock_threshold' => 7,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Supplement::query()->where('name', 'Whey isolate')->sole();

    expect($created->user_id)->toBe($owner->getKey())
        ->and($created->brand)->toBe('MarqueY')
        ->and($created->dosage)->toBe('30g')
        ->and($created->servings_remaining)->toBe(60)
        ->and($created->low_stock_threshold)->toBe(7);
});

it('refuse un supplément sans utilisateur ni nom', function (): void {
    $before = Supplement::query()->count();

    Livewire::test(CreateSupplement::class)
        ->fillForm([
            'user_id' => null,
            'name' => null,
            'servings_remaining' => 10,
            'low_stock_threshold' => 2,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'user_id' => 'required',
            'name' => 'required',
        ]);

    expect(Supplement::query()->count())->toBe($before);
});

it('refuse un stock non numérique', function (): void {
    $owner = User::factory()->create();

    Livewire::test(CreateSupplement::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'name' => 'Magnésium',
            'servings_remaining' => 'beaucoup',
            'low_stock_threshold' => 5,
        ])
        ->call('create')
        ->assertHasFormErrors(['servings_remaining' => 'numeric']);

    expect(Supplement::query()->where('name', 'Magnésium')->exists())->toBeFalse();
});

it('charge le supplément existant dans le formulaire et enregistre la modification', function (): void {
    $owner = User::factory()->create();
    $supplement = Supplement::factory()->create([
        'user_id' => $owner->getKey(),
        'name' => 'Ancien nom',
        'brand' => 'AncienneMarque',
        'dosage' => '2g',
        'servings_remaining' => 30,
        'low_stock_threshold' => 5,
    ]);

    Livewire::test(EditSupplement::class, ['record' => $supplement->getKey()])
        ->assertFormFieldExists('user_id')
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('brand')
        ->assertFormFieldExists('dosage')
        ->assertFormFieldExists('servings_remaining')
        ->assertFormFieldExists('low_stock_threshold')
        ->assertFormSet([
            'user_id' => (string) $owner->getKey(),
            'name' => 'Ancien nom',
            'brand' => 'AncienneMarque',
            'dosage' => '2g',
            'servings_remaining' => '30',
            'low_stock_threshold' => '5',
        ])
        ->fillForm([
            'name' => 'Nom corrigé',
            'servings_remaining' => 12,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $supplement->refresh();

    expect($supplement->name)->toBe('Nom corrigé')
        ->and($supplement->servings_remaining)->toBe(12)
        ->and($supplement->brand)->toBe('AncienneMarque');
});

it("réaffecte un supplément à un autre utilisateur depuis l'édition", function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $first->getKey()]);

    Livewire::test(EditSupplement::class, ['record' => $supplement->getKey()])
        ->fillForm(['user_id' => $second->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($supplement->fresh()->user_id)->toBe($second->getKey());
});

it('interdit la gestion des suppléments à un admin sans les permissions Supplement', function (): void {
    $supplement = Supplement::factory()->create();

    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:User']), 'admin');

    expect(SupplementResource::canViewAny())->toBeFalse()
        ->and(SupplementResource::canCreate())->toBeFalse()
        ->and(SupplementResource::canEdit($supplement))->toBeFalse()
        ->and(SupplementResource::canDelete($supplement))->toBeFalse();
});
