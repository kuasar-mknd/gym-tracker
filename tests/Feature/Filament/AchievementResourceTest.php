<?php

declare(strict_types=1);

use App\Filament\Resources\Achievements\AchievementResource;
use App\Filament\Resources\Achievements\Pages\CreateAchievement;
use App\Filament\Resources\Achievements\Pages\EditAchievement;
use App\Filament\Resources\Achievements\Pages\ListAchievements;
use App\Models\Achievement;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

beforeEach(function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Achievement')), 'admin');
});

it('liste les badges avec leur slug, leur type et leur seuil', function (): void {
    $badge = Achievement::factory()->create([
        'slug' => 'dix-seances',
        'name' => 'Dix séances',
        'icon' => '🏆',
        'type' => 'workout_count',
        'threshold' => 10,
        'category' => 'beginner',
    ]);
    $other = Achievement::factory()->create(['slug' => 'cent-seances', 'threshold' => 100]);

    Livewire::test(ListAchievements::class)
        ->assertCanSeeTableRecords([$badge, $other])
        ->assertTableColumnStateSet('slug', 'dix-seances', $badge)
        ->assertTableColumnStateSet('type', 'workout_count', $badge)
        ->assertTableColumnStateSet('threshold', 10, $badge)
        ->assertTableColumnStateSet('threshold', 100, $other)
        ->assertTableColumnStateSet('category', 'beginner', $badge);
});

it('cherche un badge par son slug', function (): void {
    $wanted = Achievement::factory()->create(['slug' => 'marathonien']);
    $unwanted = Achievement::factory()->create(['slug' => 'sprinteur']);

    Livewire::test(ListAchievements::class)
        ->searchTable('marathonien')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$unwanted]);
});

it('crée un badge avec tous ses attributs', function (): void {
    Livewire::test(CreateAchievement::class)
        ->fillForm([
            'slug' => 'premier-pas',
            'name' => 'Premier pas',
            'description' => 'Terminer sa toute première séance.',
            'icon' => '🥇',
            'type' => 'workout_count',
            'threshold' => 1,
            'category' => 'beginner',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Achievement::query()->where('slug', 'premier-pas')->sole();

    expect($created->name)->toBe('Premier pas')
        ->and($created->description)->toBe('Terminer sa toute première séance.')
        ->and($created->icon)->toBe('🥇')
        ->and($created->type)->toBe('workout_count')
        ->and((int) $created->threshold)->toBe(1)
        ->and($created->category)->toBe('beginner');
});

it('applique la catégorie general par défaut quand elle est laissée telle quelle', function (): void {
    Livewire::test(CreateAchievement::class)
        ->fillForm([
            'slug' => 'sans-categorie',
            'name' => 'Sans catégorie',
            'description' => 'Une description.',
            'icon' => '⭐',
            'type' => 'streak',
            'threshold' => 3,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Achievement::query()->where('slug', 'sans-categorie')->value('category'))->toBe('general');
});

it('refuse un badge auquel il manque les champs obligatoires', function (): void {
    $before = Achievement::query()->count();

    Livewire::test(CreateAchievement::class)
        ->fillForm([
            'slug' => null,
            'name' => null,
            'description' => null,
            'icon' => null,
            'type' => null,
            'threshold' => null,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'slug' => 'required',
            'name' => 'required',
            'description' => 'required',
            'icon' => 'required',
            'type' => 'required',
            'threshold' => 'required',
        ]);

    expect(Achievement::query()->count())->toBe($before);
});

it('refuse un seuil non numérique', function (): void {
    Livewire::test(CreateAchievement::class)
        ->fillForm([
            'slug' => 'seuil-invalide',
            'name' => 'Seuil invalide',
            'description' => 'Une description.',
            'icon' => '⭐',
            'type' => 'volume',
            'threshold' => 'énorme',
            'category' => 'advanced',
        ])
        ->call('create')
        ->assertHasFormErrors(['threshold' => 'numeric']);

    expect(Achievement::query()->where('slug', 'seuil-invalide')->exists())->toBeFalse();
});

it('charge le badge existant dans le formulaire et enregistre la modification', function (): void {
    $badge = Achievement::factory()->create([
        'slug' => 'ancien-slug',
        'name' => 'Ancien nom',
        'description' => 'Ancienne description.',
        'icon' => '🥉',
        'type' => 'streak',
        'threshold' => 5,
        'category' => 'beginner',
    ]);

    Livewire::test(EditAchievement::class, ['record' => $badge->getKey()])
        ->assertFormFieldExists('slug')
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('description')
        ->assertFormFieldExists('icon')
        ->assertFormFieldExists('type')
        ->assertFormFieldExists('threshold')
        ->assertFormFieldExists('category')
        ->assertFormSet([
            'slug' => 'ancien-slug',
            'name' => 'Ancien nom',
            'description' => 'Ancienne description.',
            'icon' => '🥉',
            'type' => 'streak',
            'threshold' => '5',
            'category' => 'beginner',
        ])
        ->fillForm([
            'name' => 'Nom corrigé',
            'threshold' => 25,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $badge->refresh();

    expect($badge->name)->toBe('Nom corrigé')
        ->and((int) $badge->threshold)->toBe(25)
        ->and($badge->slug)->toBe('ancien-slug');
});

it('interdit la gestion des badges à un admin sans les permissions Achievement', function (): void {
    $badge = Achievement::factory()->create();

    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:User']), 'admin');

    expect(AchievementResource::canViewAny())->toBeFalse()
        ->and(AchievementResource::canCreate())->toBeFalse()
        ->and(AchievementResource::canEdit($badge))->toBeFalse()
        ->and(AchievementResource::canDelete($badge))->toBeFalse();
});
