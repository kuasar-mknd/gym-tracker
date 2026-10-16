<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\OublieLesParametresIllisibles;
use App\Models\Exercise;
use App\Models\Fast;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FilamentAdminPanel;

use function Pest\Laravel\actingAs;

/*
 * Ce que des paquets lisent dans la chaîne de requête sans en vérifier la
 * forme (#2071) : un octet qui n'est pas de l'UTF-8, recopié dans du JSON,
 * vidait la page des statistiques et faisait répondre 500 à une visite
 * Inertia comme au panneau ; une page de pagination plus grande qu'un entier
 * ne tient, multipliée par la taille de la page, faisait répondre 500 à
 * chaque liste qui avait une ligne ; une recherche en tableau, à chaque liste
 * du panneau. Chaque cas vérifie aussi qu'aucune exception n'est rapportée :
 * une erreur rattrapée puis rapportée ne se verrait pas au statut.
 */

beforeEach(function (): void {
    Exceptions::fake();
});

/**
 * Les en-têtes d'une visite Inertia, à la version des actifs du serveur.
 *
 * @return array<string, string>
 */
function illisibleEntetesDeVisite(): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ];
}

it('refuse en 400 une chaîne de requête qui n’est pas de l’UTF-8, page complète comme visite Inertia', function (string $adresse, bool $visiteInertia): void {
    actingAs(User::factory()->create())
        ->get($adresse, $visiteInertia ? illisibleEntetesDeVisite() : [])
        ->assertStatus(400);

    Exceptions::assertNothingReported();
})->with([
    'valeur recopiée par la page' => '/stats?period=%FF%FE',
    'octet tronqué' => '/stats?period=%C3',
    'clef' => '/dashboard?%FF=1',
    'clef imbriquée' => '/workouts?filtre[%FF]=1',
])->with([
    'page complète' => false,
    'visite Inertia' => true,
]);

it('refuse en 400 un formulaire qui n’est pas de l’UTF-8, et n’écrit rien', function (string $type, string $champ): void {
    $compte = User::factory()->create(['name' => 'Avant']);
    $valeur = "Nom\xFF\xFE";
    $envois = [
        ['PATCH', route('profile.update'), ['name' => $valeur, 'email' => $compte->email]],
        ['POST', route('templates.store'), ['name' => $valeur, 'description' => $valeur]],
        ['POST', route('exercises.store'), ['name' => $valeur, 'type' => 'strength']],
    ];

    foreach ($envois as [$methode, $adresse, $corps]) {
        $corps = $champ === 'clef' ? [$valeur => 'x', ...array_combine(array_keys($corps), array_fill(0, count($corps), 'Nom'))] : $corps;

        actingAs($compte)
            ->call($methode, $adresse, $corps, [], [], ['CONTENT_TYPE' => $type])
            ->assertStatus(400);
    }

    expect($compte->fresh()?->name)->toBe('Avant')
        ->and(WorkoutTemplate::query()->where('user_id', $compte->id)->exists())->toBeFalse()
        ->and(Exercise::query()->where('user_id', $compte->id)->exists())->toBeFalse();
    Exceptions::assertNothingReported();
})->with([
    'formulaire' => 'application/x-www-form-urlencoded',
    'formulaire en plusieurs parties' => 'multipart/form-data; boundary=----limite',
])->with([
    'valeur' => 'valeur',
    'clef' => 'clef',
]);

it('enregistre comme avant un formulaire en UTF-8 qui n’est pas de l’ASCII', function (): void {
    $compte = User::factory()->create(['name' => 'Avant']);

    actingAs($compte)
        ->call('PATCH', route('profile.update'), ['name' => 'Éloïse', 'email' => $compte->email], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($compte->fresh()?->name)->toBe('Éloïse');
    Exceptions::assertNothingReported();
});

it('laisse passer une adresse en UTF-8 qui n’est pas de l’ASCII, telle quelle', function (): void {
    actingAs(User::factory()->create())
        ->get('/stats?period=%C3%A9t%C3%A9')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('selectedPeriod', 'été'));

    Exceptions::assertNothingReported();
});

it('lit comme la première une page plus grande que ce que la pagination sait sauter', function (string $route, string $prop, Closure $uneLigne): void {
    $compte = User::factory()->create();
    $uneLigne($compte);

    actingAs($compte)
        ->get(route($route).'?page=9223372036854775807')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where("{$prop}.current_page", 1)->count("{$prop}.data", 1));

    Exceptions::assertNothingReported();
})->with([
    'séances' => ['workouts.index', 'workouts', fn (User $compte): Workout => Workout::withoutEvents(fn (): Workout => Workout::factory()->create(['user_id' => $compte->id]))],
    'notifications' => ['notifications.index', 'notifications', fn (User $compte): DatabaseNotification => $compte->notifications()->create([
        'id' => Str::uuid()->toString(),
        'type' => \App\Notifications\PersonalRecordAchieved::class,
        'data' => ['message' => 'Record'],
        'read_at' => null,
    ])],
    'jeûnes terminés' => ['tools.fasting.index', 'history', fn (User $compte): Fast => Fast::factory()->create(['user_id' => $compte->id, 'status' => 'completed', 'end_time' => now()])],
]);

it('lit une page jusqu’à la borne comme avant, et une page illisible comme la première', function (string $page, int $pageLue): void {
    $compte = User::factory()->create();
    Workout::withoutEvents(fn (): Workout => Workout::factory()->create(['user_id' => $compte->id]));

    actingAs($compte)
        ->get(route('workouts.index').'?page='.$page)
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('workouts.current_page', $pageLue));

    Exceptions::assertNothingReported();
})->with([
    'deux' => ['2', 2],
    'la borne' => [(string) OublieLesParametresIllisibles::PAGE_AU_PLUS, OublieLesParametresIllisibles::PAGE_AU_PLUS],
    'au-delà de la borne' => [(string) (OublieLesParametresIllisibles::PAGE_AU_PLUS + 1), 1],
    'zéro' => ['0', 1],
    'pas un nombre' => ['abc', 1],
    'tableau' => ['2&page[]=3', 1],
]);

it('garde chaque liste du panneau debout devant une recherche en tableau ou une page trop grande', function (string $chaineDeRequete): void {
    $admin = FilamentAdminPanel::admin(['ViewAny:User']);
    User::factory()->create(['name' => 'Compte Visible']);
    RateLimiter::clear('admin-panel:127.0.0.1');

    $this->actingAs($admin, 'admin')
        ->get('/backoffice/users?'.$chaineDeRequete)
        ->assertOk()
        ->assertSee('Compte Visible');

    Exceptions::assertNothingReported();
})->with([
    'recherche en tableau' => 'search[]=Visible',
    'recherche en tableau imbriqué' => 'search[a][b]=Visible',
    'page trop grande' => 'page=9223372036854775807',
]);

it('refuse en 400 une recherche du panneau qui n’est pas de l’UTF-8, et lit une recherche en texte comme avant', function (): void {
    $admin = FilamentAdminPanel::admin(['ViewAny:User']);
    User::factory()->create(['name' => 'Compte Visible']);
    User::factory()->create(['name' => 'Compte Cache']);

    foreach (['search', 'sort', 'tab', 'filters[a]'] as $nom) {
        RateLimiter::clear('admin-panel:127.0.0.1');
        $this->actingAs($admin, 'admin')->get("/backoffice/users?{$nom}=%FF")->assertStatus(400);
    }

    RateLimiter::clear('admin-panel:127.0.0.1');
    $this->actingAs($admin, 'admin')
        ->get('/backoffice/users?search=Visible')
        ->assertOk()
        ->assertSee('Compte Visible')
        ->assertDontSee('Compte Cache');

    Exceptions::assertNothingReported();
});
