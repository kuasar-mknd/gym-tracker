<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\ExceptionEnregistree;
use BezhanSalleh\FilamentExceptions\Resources\ExceptionResource;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

/**
 * Le plus d'octets qu'occupe la ligne d'une exception : la somme des budgets
 * de `ExceptionEnregistree::OCTETS_MAX_PAR_COLONNE` (90 Kio), des colonnes
 * textuelles courtes à quatre octets par caractère au pire (un peu plus de
 * 11 Kio), et de la marge que prend MySQL à réécrire le JSON.
 */
function exceptionsTailleMaxDeLaLigne(): int
{
    return 131_072;
}

/**
 * La taille qu'occupe la ligne d'une exception, toutes colonnes comprises,
 * telle que MySQL la rend (le JSON réécrit par lui).
 */
function exceptionsTailleDeLaLigne(): int
{
    $ligne = DB::table('filament_exceptions_table')->sole();

    return array_sum(array_map(
        static fn (mixed $valeur): int => is_scalar($valeur) ? strlen((string) $valeur) : 0,
        (array) $ligne,
    ));
}

/*
 * Le paquet garde le corps entier de la requête, et le message d'une erreur
 * SQL recopie les valeurs envoyées, comme la pile (arguments compris) et le
 * texte Markdown : une requête de plusieurs mégaoctets qui finissait en
 * erreur écrivait une ligne aussi grosse qu'elle.
 */
it('borne la ligne d’une exception, quel que soit le corps de la requête', function (): void {
    Route::post('/_boum', function (Request $requete): never {
        $recopie = static function (string $valeur): never {
            throw new RuntimeException('boum : '.$valeur);
        };

        $recopie($requete->string('notes')->toString());
    });

    $this->post('/_boum', [
        'notes' => str_repeat('é', 1_000_000),
        'champs' => array_fill(0, 20_000, 'valeur'),
        'password' => 'secret',
        str_repeat('k', 10_000) => 'clé démesurée',
    ])->assertStatus(500);

    $exception = ExceptionEnregistree::query()->sole();

    expect(exceptionsTailleDeLaLigne())->toBeLessThanOrEqual(exceptionsTailleMaxDeLaLigne())
        ->and($exception->message)->toStartWith('boum : éé')
        ->and($exception->message)->toEndWith('[tronqué]')
        ->and(mb_check_encoding($exception->message, 'UTF-8'))->toBeTrue()
        ->and($exception->trace)->not->toBeEmpty()
        ->and($exception->trace[0])->toBeArray()
        ->and($exception->body)->toBeArray()
        ->and($exception->body['password'] ?? null)->toBe('[masqué]')
        ->and($exception->body['notes'] ?? null)->toBeString()->toEndWith('[tronqué]');

    foreach (ExceptionEnregistree::OCTETS_MAX_PAR_COLONNE as $colonne => $octets) {
        $valeur = DB::table('filament_exceptions_table')->value($colonne);

        expect(strlen(is_scalar($valeur) ? (string) $valeur : ''))->toBeLessThanOrEqual((int) ($octets * 1.1), "{$colonne} dépasse son budget");
    }
});

/*
 * Le Markdown n'est écrit qu'en mode debug, et les requêtes SQL qu'avec leur
 * écouteur : chaque colonne est bornée quand même, écrite directement.
 */
it('borne chaque colonne longue, Markdown et requêtes SQL compris', function (): void {
    $enorme = str_repeat('x', 100_000);

    ExceptionEnregistree::query()->create([
        'type' => str_repeat('T', 400),
        'code' => '0',
        'message' => $enorme,
        'file' => str_repeat('f', 400),
        'line' => 12,
        'trace' => array_fill(0, 50, ['file' => '/app/vendor/x.php', 'line' => 1, 'function' => 'f', 'args' => [['string', $enorme]]]),
        'method' => 'POST',
        'path' => str_repeat('p', 5_000),
        'ip' => '203.0.113.50',
        'headers' => ['x-long' => [$enorme], 'user-agent' => ['test']],
        'body' => ['a' => ['b' => ['c' => $enorme]]],
        'query' => array_fill(0, 50, ['connectionName' => 'mysql', 'sql' => $enorme, 'time' => 1.0]),
        'route_context' => ['controller' => $enorme],
        'route_parameters' => ['reste' => $enorme],
        'markdown' => $enorme,
    ]);

    $exception = ExceptionEnregistree::query()->sole();

    expect(exceptionsTailleDeLaLigne())->toBeLessThanOrEqual(exceptionsTailleMaxDeLaLigne())
        ->and($exception->markdown)->toEndWith('[tronqué]')
        ->and($exception->query)->not->toBeEmpty()
        ->and($exception->query[0]['connectionName'] ?? null)->toBe('mysql')
        ->and($exception->trace[0]['file'] ?? null)->toBe('/app/vendor/x.php')
        ->and(mb_strlen($exception->type))->toBe(255);

    foreach (ExceptionEnregistree::OCTETS_MAX_PAR_COLONNE as $colonne => $octets) {
        $valeur = DB::table('filament_exceptions_table')->value($colonne);

        expect(strlen(is_scalar($valeur) ? (string) $valeur : ''))->toBeLessThanOrEqual((int) ($octets * 1.1), "{$colonne} dépasse son budget");
    }
});

it('garde entière l’exception d’une petite requête', function (): void {
    Route::post('/_boum', function (): never {
        throw new RuntimeException('boum');
    });

    $this->post('/_boum', ['name' => 'Rowing', 'profil' => ['age' => 30, 'ville' => 'Lyon']])->assertStatus(500);

    $exception = ExceptionEnregistree::query()->sole();

    expect($exception->message)->toBe('boum')
        ->and($exception->body)->toEqual(['name' => 'Rowing', 'profil' => ['age' => 30, 'ville' => 'Lyon']])
        ->and((string) $exception->markdown)->not->toContain('[tronqué]');
});

/*
 * Un chemin plus long que sa colonne faisait refuser l'écriture : l'exception
 * n'était pas gardée du tout, sans que rien ne le dise.
 */
it('garde l’exception d’une requête dont le chemin dépasse sa colonne', function (): void {
    Route::post('/_boum/{reste}', function (): never {
        throw new RuntimeException('boum');
    })->where('reste', '.*');

    $this->post('/_boum/'.str_repeat('a', 3_000))->assertStatus(500);

    expect(mb_strlen(ExceptionEnregistree::query()->sole()->path))->toBe(2_048);
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
