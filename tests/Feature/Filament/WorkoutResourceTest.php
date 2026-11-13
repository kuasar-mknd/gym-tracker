<?php

declare(strict_types=1);

use App\Filament\Resources\Workouts\Pages\CreateWorkout;
use App\Filament\Resources\Workouts\Pages\EditWorkout;
use App\Filament\Resources\Workouts\Pages\ListWorkouts;
use App\Filament\Resources\Workouts\WorkoutResource;
use App\Models\User;
use App\Models\Workout;
use App\Services\RecommendedValuesService;
use App\Services\Stats\ClesDeStats;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

beforeEach(function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Workout')), 'admin');
});

it('liste les séances avec leur propriétaire et leurs horaires', function (): void {
    $owner = User::factory()->create(['name' => 'Sportive Sophie']);
    $workout = Workout::factory()->create([
        'user_id' => $owner->getKey(),
        'name' => 'Séance du lundi',
        'started_at' => Carbon::parse('2026-03-02 18:00:00'),
        'ended_at' => Carbon::parse('2026-03-02 19:15:00'),
    ]);

    Livewire::test(ListWorkouts::class)
        ->assertCanSeeTableRecords([$workout])
        ->assertTableColumnStateSet('user.name', 'Sportive Sophie', $workout)
        ->assertTableColumnStateSet('name', 'Séance du lundi', $workout)
        ->assertTableColumnStateSet('started_at', Carbon::parse('2026-03-02 18:00:00'), $workout)
        ->assertTableColumnStateSet('ended_at', Carbon::parse('2026-03-02 19:15:00'), $workout);
});

it('trie les séances de la plus récente à la plus ancienne', function (): void {
    $oldest = Workout::factory()->create(['started_at' => Carbon::parse('2026-01-01 08:00:00')]);
    $newest = Workout::factory()->create(['started_at' => Carbon::parse('2026-06-01 08:00:00')]);
    $middle = Workout::factory()->create(['started_at' => Carbon::parse('2026-03-01 08:00:00')]);

    Livewire::test(ListWorkouts::class)
        ->sortTable('started_at', 'desc')
        ->assertCanSeeTableRecords([$newest, $middle, $oldest], inOrder: true);
});

it('cherche une séance par le nom de son propriétaire', function (): void {
    $wantedOwner = User::factory()->create(['name' => 'Gaspard Cherché']);
    $wanted = Workout::factory()->create(['user_id' => $wantedOwner->getKey()]);
    $unwanted = Workout::factory()->create();

    Livewire::test(ListWorkouts::class)
        ->searchTable('Gaspard Cherché')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$unwanted]);
});

it('refuse une séance sans utilisateur et sans date de début', function (): void {
    $before = Workout::query()->count();

    Livewire::test(CreateWorkout::class)
        ->fillForm([
            'user_id' => null,
            'name' => 'Séance orpheline',
            'started_at' => null,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'user_id' => 'required',
            'started_at' => 'required',
        ]);

    expect(Workout::query()->count())->toBe($before);
});

it("refuse une date de début qui n'est pas une date", function (): void {
    $owner = User::factory()->create();

    Livewire::test(CreateWorkout::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'name' => 'Séance mal datée',
            'started_at' => 'un jour de pluie',
        ])
        ->call('create')
        ->assertHasFormErrors(['started_at']);

    expect(Workout::query()->where('name', 'Séance mal datée')->exists())->toBeFalse();
});

/*
 * Le début d'une séance devient la dernière séance du compte, un horodatage :
 * hors de sa plage, la base refusait la série de jours recalculée, et
 * l'enregistrement répondait 500 ; la fin, un `DATETIME`, faisait de même en
 * l'an 10000 dans le formatage de Filament (#2071).
 */
it('refuse une date que la colonne de la séance ne tient pas', function (string $champ, string $date): void {
    $workout = Workout::factory()->create([
        'started_at' => Carbon::parse('2026-02-10 07:30:00'),
        'ended_at' => Carbon::parse('2026-02-10 08:45:00'),
    ]);

    Livewire::test(EditWorkout::class, ['record' => $workout->getKey()])
        ->fillForm([$champ => $date])
        ->call('save')
        ->assertHasFormErrors([$champ]);

    expect($workout->fresh()?->started_at?->format('Y-m-d H:i:s'))->toBe('2026-02-10 07:30:00')
        ->and($workout->fresh()?->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-02-10 08:45:00');
})->with([
    'début après 2038' => ['started_at', '2099-01-01 10:00:00'],
    'début avant 1970' => ['started_at', '1960-01-01 10:00:00'],
    'fin en l’an 10000' => ['ended_at', '+10000-01-01 10:00:00'],
]);

it("charge la séance existante dans le formulaire d'édition", function (): void {
    $owner = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $owner->getKey(),
        'name' => 'Nom initial',
        'started_at' => Carbon::parse('2026-02-10 07:30:00'),
        'ended_at' => Carbon::parse('2026-02-10 08:45:00'),
        'notes' => 'Bonne séance.',
    ]);

    Livewire::test(EditWorkout::class, ['record' => $workout->getKey()])
        ->assertFormFieldExists('user_id')
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('started_at')
        ->assertFormFieldExists('ended_at')
        ->assertFormFieldExists('notes')
        ->assertFormSet([
            'user_id' => (string) $owner->getKey(),
            'name' => 'Nom initial',
            'started_at' => '2026-02-10 07:30:00',
            'ended_at' => '2026-02-10 08:45:00',
            'notes' => 'Bonne séance.',
        ]);
});

/*
 * Le propriétaire se choisit à la création, puis se lit seulement (#1933).
 *
 * Désactivé plutôt que masqué : l'exploitant voit toujours à qui appartient la
 * séance qu'il modifie. La page de création, elle, doit garder le choix.
 */
it('laisse choisir le propriétaire à la création et le fige à la modification', function (): void {
    $owner = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $owner->getKey()]);

    Livewire::test(CreateWorkout::class)
        ->assertFormFieldEnabled('user_id');

    Livewire::test(EditWorkout::class, ['record' => $workout->getKey()])
        ->assertFormFieldDisabled('user_id')
        ->assertFormSet(['user_id' => (string) $owner->id]);
});

it('interdit la gestion des séances à un admin sans les permissions Workout', function (): void {
    $workout = Workout::factory()->create();

    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:User']), 'admin');

    expect(WorkoutResource::canViewAny())->toBeFalse()
        ->and(WorkoutResource::canCreate())->toBeFalse()
        ->and(WorkoutResource::canEdit($workout))->toBeFalse()
        ->and(WorkoutResource::canDelete($workout))->toBeFalse();
});

/*
 * Le début et la fin d'une séance s'écrivent avec leur décalage (#2159). Le
 * champ du panneau ne montre que l'heure murale de l'application, que les
 * deux occurrences de l'heure répétée au passage à l'heure d'hiver partagent :
 * relue comme une heure sans décalage, elle replaçait dans la seconde
 * occurrence, une heure plus tard, la séance commencée dans la première, au
 * premier enregistrement venu, un simple renommage compris.
 */
dataset('enregistrements d’une séance du panneau', [
    'la page de modification' => [fn (Workout $seance): mixed => Livewire::test(EditWorkout::class, ['record' => $seance->getKey()])
        ->fillForm(['name' => 'Renommée au panneau'])
        ->call('save')
        ->assertHasNoFormErrors()],
    'l’action de modification de la table' => [fn (Workout $seance): mixed => Livewire::test(ListWorkouts::class)
        ->callAction(TestAction::make('edit')->table($seance), ['name' => 'Renommée au panneau'])
        ->assertHasNoFormErrors()],
]);

it('garde l’occurrence du début et de la fin d’une séance qu’il renomme', function (Closure $enregistrer): void {
    $fuseau = config()->string('app.timezone');
    $seance = Workout::factory()->create([
        'started_at' => Carbon::parse('2026-10-25T00:30:00Z')->setTimezone($fuseau),
        'ended_at' => Carbon::parse('2026-10-25T00:50:00Z')->setTimezone($fuseau),
    ]);

    $enregistrer($seance);

    $seance->refresh();

    expect($seance->name)->toBe('Renommée au panneau')
        ->and($seance->started_at->toJSON())->toBe('2026-10-25T00:30:00.000000Z')
        ->and($seance->ended_at?->toJSON())->toBe('2026-10-25T00:50:00.000000Z');
})->with('enregistrements d’une séance du panneau');

it('écrit le décalage du début et de la fin qu’il reçoit, une heure de l’heure répétée dans la seconde occurrence', function (): void {
    $proprietaire = User::factory()->create();

    Livewire::test(CreateWorkout::class)
        ->fillForm([
            'user_id' => $proprietaire->getKey(),
            'name' => 'Saisie au panneau',
            'started_at' => '2026-10-25 02:30:00',
            'ended_at' => '2026-10-25 04:00:00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $seance = Workout::query()->where('name', 'Saisie au panneau')->sole();

    expect($seance->started_at->toJSON())->toBe('2026-10-25T01:30:00.000000Z')
        ->and($seance->ended_at?->toJSON())->toBe('2026-10-25T03:00:00.000000Z')
        ->and($seance->getRawOriginal('started_at_offset'))->toBe(60)
        ->and($seance->getRawOriginal('ended_at_offset'))->toBe(60);

    Livewire::test(EditWorkout::class, ['record' => $seance->getKey()])
        ->fillForm(['started_at' => '2026-10-24 21:00:00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($seance->refresh()->started_at->toJSON())->toBe('2026-10-24T19:00:00.000000Z')
        ->and($seance->getRawOriginal('started_at_offset'))->toBe(120);
});

/*
 * Une heure changée au panneau ne porte pas de décalage. Elle reprend celui de
 * l'instant enregistré tant qu'il redonne cette heure murale (#2159) : une
 * séance de la première occurrence dont on retouche le début ou la fin dans
 * l'heure répétée y reste, à côté de l'autre champ laissé tel quel, et
 * l'écran, qui montrait le début avant la fin, dit vrai. Lue seule, l'heure
 * passait dans la seconde occurrence, une heure plus tard : la fin tombait
 * avant le début (-50 min), ou la séance gagnait une heure.
 */
dataset('modifications d’une séance du panneau', [
    'la page de modification' => [fn (Workout $seance, array $donnees): mixed => Livewire::test(EditWorkout::class, ['record' => $seance->getKey()])
        ->fillForm($donnees)
        ->call('save')
        ->assertHasNoFormErrors()],
    'l’action de modification de la table' => [fn (Workout $seance, array $donnees): mixed => Livewire::test(ListWorkouts::class)
        ->callAction(TestAction::make('edit')->table($seance), $donnees)
        ->assertHasNoFormErrors()],
]);

it('lit une heure changée avec le décalage enregistré quand il redonne cette heure', function (Closure $modifier, bool $sansDecalage, array $donnees, string $debut, string $fin): void {
    $fuseau = config()->string('app.timezone');
    $seance = ($sansDecalage ? Workout::factory()->sansDecalage() : Workout::factory())->create([
        'started_at' => Carbon::parse('2026-10-25T00:30:00Z')->setTimezone($fuseau),
        'ended_at' => Carbon::parse('2026-10-25T00:50:00Z')->setTimezone($fuseau),
    ]);

    $modifier($seance, $donnees);

    $seance->refresh();

    expect($seance->started_at->toJSON())->toBe($debut)
        ->and($seance->ended_at?->toJSON())->toBe($fin);
})->with('modifications d’une séance du panneau')->with([
    'début changé dans l’heure répétée, séance de la première occurrence' => [false, ['started_at' => '2026-10-25 02:40:00'], '2026-10-25T00:40:00.000000Z', '2026-10-25T00:50:00.000000Z'],
    'fin changée dans l’heure répétée, séance de la première occurrence' => [false, ['ended_at' => '2026-10-25 02:55:00'], '2026-10-25T00:30:00.000000Z', '2026-10-25T00:55:00.000000Z'],
    'contre-épreuve : début changé, séance écrite sans décalage (seconde occurrence)' => [true, ['started_at' => '2026-10-25 02:40:00'], '2026-10-25T01:40:00.000000Z', '2026-10-25T01:50:00.000000Z'],
    'contre-épreuve : début changé avant l’heure répétée, où le décalage enregistré va encore' => [false, ['started_at' => '2026-10-25 01:40:00'], '2026-10-24T23:40:00.000000Z', '2026-10-25T00:50:00.000000Z'],
    'contre-épreuve : fin changée après l’heure répétée, où le décalage enregistré ne va plus' => [false, ['ended_at' => '2026-10-25 03:20:00'], '2026-10-25T00:30:00.000000Z', '2026-10-25T02:20:00.000000Z'],
]);

/*
 * Le panneau écrit les séances sans passer par les actions de l'application, qui
 * seules renouvelaient les versions de cache des statistiques et des valeurs
 * proposées : une séance créée, changée ou supprimée au panneau laissait les
 * chiffres d'avant jusqu'à leur échéance. La suppression, qui efface lignes et
 * séries en base sans événement de série, ne renouvelait jamais les valeurs
 * proposées. `Workout::booted()` les renouvelle après la transaction de
 * l'écrivain. Les clefs se relisent par leurs lecteurs au moment de
 * l'assertion, jamais depuis une variable remplie avant l'écriture.
 */

/**
 * Les deux clefs que lit l'application pour ce compte, à cet instant.
 *
 * @return array{string, string}
 */
function clesDeCacheDuCompte(User $compte): array
{
    return [
        ClesDeStats::seances($compte, 'sonde'),
        RecommendedValuesService::cleDeCache($compte->id, 1, 1),
    ];
}

it('renouvelle les versions du cache à la création, à la modification et à la suppression d’une séance du panneau', function (string $geste): void {
    $compte = User::factory()->create();
    $seance = Workout::factory()->create([
        'user_id' => $compte->getKey(),
        'started_at' => Carbon::parse('2026-02-10 07:30:00'),
        'ended_at' => Carbon::parse('2026-02-10 08:45:00'),
    ]);
    $avant = clesDeCacheDuCompte($compte);

    match ($geste) {
        'création' => Livewire::test(CreateWorkout::class)
            ->fillForm(['user_id' => $compte->getKey(), 'started_at' => '2026-03-01 10:00:00'])
            ->call('create')
            ->assertHasNoFormErrors(),
        'page de modification' => Livewire::test(EditWorkout::class, ['record' => $seance->getKey()])
            ->fillForm(['ended_at' => '2026-02-10 09:15:00'])
            ->call('save')
            ->assertHasNoFormErrors(),
        'action de la table' => Livewire::test(ListWorkouts::class)
            ->callAction(TestAction::make('edit')->table($seance), ['started_at' => '2026-02-09 07:30:00'])
            ->assertHasNoFormErrors(),
        'suppression' => Livewire::test(EditWorkout::class, ['record' => $seance->getKey()])
            ->callAction(DeleteAction::class),
        'suppression groupée' => Livewire::test(ListWorkouts::class)
            ->callTableBulkAction('delete', [$seance]),
        default => throw new LogicException("Geste inconnu : {$geste}"),
    };

    $apres = clesDeCacheDuCompte($compte);

    expect($apres[0])->not->toBe($avant[0])
        ->and($apres[1])->not->toBe($avant[1]);
})->with(['création', 'page de modification', 'action de la table', 'suppression', 'suppression groupée']);

it('garde les versions du cache quand l’enregistrement ne change ni les dates, ni le nom', function (): void {
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->getKey()]);
    $avant = clesDeCacheDuCompte($compte);

    Livewire::test(EditWorkout::class, ['record' => $seance->getKey()])
        ->fillForm(['notes' => 'Une note de plus.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(clesDeCacheDuCompte($compte))->toBe($avant);
});

/*
 * La fin d'une séance ne précède pas son début, et ne reste pas vide sous une
 * autre séance en cours du compte : le démarrage refuse déjà d'en ouvrir une
 * seconde, et le reste de l'application lit « la séance en cours » au
 * singulier. Rouvrir une séance (vider sa fin) reste permis quand rien d'autre
 * n'est en cours.
 */
dataset('enregistrements refusés d’une séance du panneau', [
    'la page de modification' => [fn (Workout $seance, array $donnees): mixed => Livewire::test(EditWorkout::class, ['record' => $seance->getKey()])
        ->fillForm($donnees)
        ->call('save')],
    'l’action de modification de la table' => [fn (Workout $seance, array $donnees): mixed => Livewire::test(ListWorkouts::class)
        ->callAction(TestAction::make('edit')->table($seance), $donnees)],
]);

it('refuse une fin qui précède le début', function (Closure $enregistrer): void {
    $seance = Workout::factory()->create([
        'started_at' => Carbon::parse('2026-02-10 07:30:00'),
        'ended_at' => Carbon::parse('2026-02-10 08:45:00'),
    ]);

    $enregistrer($seance, ['ended_at' => '2026-02-10 07:00:00'])->assertHasFormErrors(['ended_at']);

    expect($seance->fresh()?->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-02-10 08:45:00');
})->with('enregistrements refusés d’une séance du panneau');

it('accepte une fin égale au début', function (Closure $enregistrer): void {
    $seance = Workout::factory()->create([
        'started_at' => Carbon::parse('2026-02-10 07:30:00'),
        'ended_at' => Carbon::parse('2026-02-10 08:45:00'),
    ]);

    $enregistrer($seance, ['ended_at' => '2026-02-10 07:30:00'])->assertHasNoFormErrors();

    expect($seance->fresh()?->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-02-10 07:30:00');
})->with('enregistrements refusés d’une séance du panneau');

it('refuse de vider la fin d’une séance quand le compte a déjà une autre séance en cours', function (Closure $enregistrer): void {
    $compte = User::factory()->create();
    Workout::factory()->create(['user_id' => $compte->getKey(), 'ended_at' => null]);
    $terminee = Workout::factory()->create([
        'user_id' => $compte->getKey(),
        'started_at' => Carbon::parse('2026-02-10 07:30:00'),
        'ended_at' => Carbon::parse('2026-02-10 08:45:00'),
    ]);

    $enregistrer($terminee, ['ended_at' => null])->assertHasFormErrors(['ended_at']);

    expect($terminee->fresh()?->ended_at)->not->toBeNull();
})->with('enregistrements refusés d’une séance du panneau');

it('laisse rouvrir une séance quand le compte n’en a aucune autre en cours', function (Closure $enregistrer): void {
    $compte = User::factory()->create();
    $terminee = Workout::factory()->create([
        'user_id' => $compte->getKey(),
        'started_at' => Carbon::parse('2026-02-10 07:30:00'),
        'ended_at' => Carbon::parse('2026-02-10 08:45:00'),
    ]);
    Workout::factory()->create(['user_id' => User::factory()->create()->getKey(), 'ended_at' => null]);

    $enregistrer($terminee, ['ended_at' => null])->assertHasNoFormErrors();

    expect($terminee->fresh()?->ended_at)->toBeNull();
})->with('enregistrements refusés d’une séance du panneau');

it('laisse une séance déjà en cours le rester, même quand le compte en a une autre', function (Closure $enregistrer): void {
    $compte = User::factory()->create();
    Workout::factory()->create(['user_id' => $compte->getKey(), 'ended_at' => null]);
    $enCours = Workout::factory()->create(['user_id' => $compte->getKey(), 'ended_at' => null]);

    $enregistrer($enCours, ['name' => 'Toujours en cours'])->assertHasNoFormErrors();

    expect($enCours->fresh()?->name)->toBe('Toujours en cours');
})->with('enregistrements refusés d’une séance du panneau');

it('refuse de créer une séance sans fin quand le compte en a déjà une en cours', function (): void {
    $compte = User::factory()->create();
    Workout::factory()->create(['user_id' => $compte->getKey(), 'ended_at' => null]);

    Livewire::test(CreateWorkout::class)
        ->fillForm(['user_id' => $compte->getKey(), 'name' => 'Seconde séance', 'started_at' => '2026-03-01 10:00:00'])
        ->call('create')
        ->assertHasFormErrors(['ended_at']);

    expect(Workout::query()->where('name', 'Seconde séance')->exists())->toBeFalse();
});

/*
 * La fin se compare au début en instants, décalage compris : une séance de
 * 02:50 CEST à 02:10 CET (vingt minutes) a une fin murale avant son début, et
 * un simple renommage ne doit pas être refusé.
 */
it('enregistre une séance finie dans l’heure répétée, dont l’heure murale de fin précède celle du début', function (Closure $enregistrer): void {
    $fuseau = config()->string('app.timezone');
    $seance = Workout::factory()->create([
        'started_at' => Carbon::parse('2026-10-25T00:50:00Z')->setTimezone($fuseau),
        'ended_at' => Carbon::parse('2026-10-25T01:10:00Z')->setTimezone($fuseau),
    ]);

    $enregistrer($seance, ['notes' => 'Une note.'])->assertHasNoFormErrors();

    $seance->refresh();

    expect($seance->notes)->toBe('Une note.')
        ->and($seance->started_at->toJSON())->toBe('2026-10-25T00:50:00.000000Z')
        ->and($seance->ended_at?->toJSON())->toBe('2026-10-25T01:10:00.000000Z');
})->with('enregistrements refusés d’une séance du panneau');

it('refuse par une erreur de validation, et non par une erreur 500, une date illisible', function (string $champ): void {
    $seance = Workout::factory()->create([
        'started_at' => Carbon::parse('2026-02-10 07:30:00'),
        'ended_at' => Carbon::parse('2026-02-10 08:45:00'),
    ]);

    Livewire::test(EditWorkout::class, ['record' => $seance->getKey()])
        ->set("data.{$champ}", 'pas une date')
        ->call('save')
        ->assertHasFormErrors([$champ]);

    Livewire::test(ListWorkouts::class)
        ->callAction(TestAction::make('edit')->table($seance), [$champ => 'pas une date'])
        ->assertHasFormErrors([$champ]);
})->with(['started_at', 'ended_at']);

it('dit que la fin n’existe pas quand Laravel la laisse passer, et se tait sur la fin quand c’est le début qui n’existe pas', function (): void {
    $seance = Workout::factory()->create([
        'started_at' => Carbon::parse('2026-02-10 07:30:00'),
        'ended_at' => Carbon::parse('2026-02-10 08:45:00'),
    ]);

    Livewire::test(EditWorkout::class, ['record' => $seance->getKey()])
        ->set('data.ended_at', '99999-01-01 00:00:00')
        ->call('save')
        ->assertHasFormErrors(['ended_at' => 'Cette date n’existe pas.']);

    Livewire::test(EditWorkout::class, ['record' => $seance->getKey()])
        ->set('data.ended_at', '2026-02-30 08:45:00')
        ->call('save')
        ->assertHasFormErrors(['ended_at']);

    $fin = Livewire::test(EditWorkout::class, ['record' => $seance->getKey()])
        ->set('data.started_at', '2026-02-30 07:30:00')
        ->call('save')
        ->assertHasFormErrors(['started_at']);

    expect($fin->errors()->get('data.ended_at'))->toBe([]);
});

it('refuse toujours une fin qui précède le début dans l’heure répétée', function (Closure $enregistrer): void {
    $fuseau = config()->string('app.timezone');
    $seance = Workout::factory()->create([
        'started_at' => Carbon::parse('2026-10-25T01:10:00Z')->setTimezone($fuseau),
        'ended_at' => Carbon::parse('2026-10-25T01:30:00Z')->setTimezone($fuseau),
    ]);

    $enregistrer($seance, ['ended_at' => '2026-10-25 02:05:00'])->assertHasFormErrors(['ended_at']);
})->with('enregistrements refusés d’une séance du panneau');
