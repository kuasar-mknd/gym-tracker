<?php

declare(strict_types=1);

use App\Models\ExceptionEnregistree;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * Ni le jeton de diagnostic ni la valeur d'un en-tête `Bearer …` ne
 * s'écrivent dans la table des exceptions (#2032). Le paquet y garde le corps
 * de la requête, ses en-têtes, son chemin, ses paramètres de route, et, en
 * mode debug, ses requêtes SQL et un texte Markdown qui recopie les en-têtes ;
 * le message et la pile recopient ce qu'on leur passe. Le masque par clef
 * (`authorization`) ne voit pas un jeton passé ailleurs : dans un paramètre,
 * un autre en-tête, un champ, le chemin. `ExceptionEnregistree` les masque
 * donc par valeur, dans chaque chaîne de chaque colonne, clefs comprises,
 * avant de couper chaque colonne à son budget : coupé d'abord, un jeton à
 * cheval sur la limite n'aurait plus sa forme entière, et son début resterait.
 *
 * Les jetons se bâtissent par concaténation : aucun n'apparaît en clair dans
 * le dépôt (`AucunJetonDeDiagnosticNEstSuiviTest`).
 */

afterEach(function (): void {
    ini_restore('zend.exception_ignore_args');
});

/**
 * L'identifiant du jeton semé, ses douze caractères `[a-z0-9]`.
 */
function jetonsHorsDesExceptionsIdentifiant(): string
{
    return str_repeat('k7', 6);
}

/**
 * Le secret du jeton semé, ses quarante-huit caractères `[A-Za-z0-9]`.
 */
function jetonsHorsDesExceptionsSecret(): string
{
    return str_repeat('Zq9', 16);
}

/**
 * Le jeton de diagnostic semé, au format `gtdiag_<identifiant>_<secret>`.
 */
function jetonsHorsDesExceptionsJeton(): string
{
    return 'gtdiag_'.jetonsHorsDesExceptionsIdentifiant().'_'.jetonsHorsDesExceptionsSecret();
}

/**
 * Le secret d'un autre porteur, présenté par `Bearer`, qui n'a pas la forme
 * du jeton de diagnostic.
 */
function jetonsHorsDesExceptionsSecretDuPorteur(): string
{
    return 'Porteur.'.str_repeat('W5e', 8).'-fin';
}

/**
 * Ce texte laisse-t-il lire un morceau du jeton semé ou du secret du
 * porteur ? Un morceau suffit : un jeton coupé avant d'être masqué en
 * laisserait un.
 */
function jetonsHorsDesExceptionsLaissentLire(string $texte): bool
{
    return array_any(
        [jetonsHorsDesExceptionsIdentifiant(), substr(jetonsHorsDesExceptionsSecret(), 0, 9), str_repeat('W5e', 3)],
        static fn (string $morceau): bool => str_contains($texte, $morceau),
    );
}

/**
 * Chaque colonne de l'exception enregistrée, en texte, telle que la base la
 * garde.
 *
 * @return array<string, string>
 */
function jetonsHorsDesExceptionsColonnes(): array
{
    $colonnes = [];

    foreach ((array) DB::table('filament_exceptions_table')->sole() as $colonne => $valeur) {
        $colonnes[(string) $colonne] = is_scalar($valeur) ? (string) $valeur : '';
    }

    return $colonnes;
}

/**
 * Les colonnes de l'exception enregistrée qui laissent lire un morceau du
 * jeton ou du secret du porteur.
 *
 * @return list<string>
 */
function jetonsHorsDesExceptionsColonnesQuiLesLaissentLire(): array
{
    return array_keys(array_filter(jetonsHorsDesExceptionsColonnes(), jetonsHorsDesExceptionsLaissentLire(...)));
}

it('masque le jeton et la valeur Bearer dans chaque colonne, à toute profondeur, clefs comprises', function (): void {
    $jeton = jetonsHorsDesExceptionsJeton();
    $porteur = 'Bearer '.jetonsHorsDesExceptionsSecretDuPorteur();

    ExceptionEnregistree::query()->create([
        'type' => RuntimeException::class,
        'code' => '0',
        'message' => "Jeton refusé : {$jeton}, en-tête « Authorization: {$porteur} ».",
        'file' => '/app/x.php',
        'line' => 1,
        'trace' => [['file' => '/app/x.php', 'line' => 1, 'function' => 'verifier', 'args' => [['string', $jeton], ['string', $porteur]]]],
        'method' => 'GET',
        'path' => "diagnostic/v1/{$jeton}",
        'ip' => '203.0.113.7',
        'query' => [['connectionName' => 'mysql', 'sql' => "select * from jetons where valeur = '{$jeton}' and entete = '{$porteur}'", 'time' => 1.0]],
        'headers' => ['x-jeton' => [$jeton], 'x-transmis' => [$porteur], 'authorization' => [$porteur], $jeton => ['clef']],
        'body' => [
            'note' => "jeton {$jeton}",
            'profond' => ['a' => ['b' => [$porteur]]],
            $jeton => 'clef',
            'components' => [['snapshot' => (string) json_encode(['data' => ['note' => $porteur, 'jeton' => $jeton]])]],
        ],
        'cookies' => ['session' => $jeton],
        'route_context' => ['controller' => 'Closure', 'route name' => 'diagnostic.etat', 'middleware' => "jeton {$jeton}"],
        'route_parameters' => ['reste' => $jeton, 'entete' => $porteur],
        'markdown' => "# RuntimeException\n\nJeton refusé : {$jeton}\n\n## Headers\n\n* **x-transmis**: {$porteur}\n",
    ]);

    expect(jetonsHorsDesExceptionsColonnesQuiLesLaissentLire())->toBe([]);

    $exception = ExceptionEnregistree::query()->sole();
    $instantane = data_get($exception->body, 'components.0.snapshot');

    expect($exception->message)->toBe('Jeton refusé : [masqué], en-tête « Authorization: Bearer [masqué] ».')
        ->and($exception->trace)->toEqual([['file' => '/app/x.php', 'line' => 1, 'function' => 'verifier', 'args' => [['string', '[masqué]'], ['string', 'Bearer [masqué]']]]])
        ->and($exception->path)->toBe('diagnostic/v1/[masqué]')
        ->and($exception->query[0]['sql'] ?? null)->toBe("select * from jetons where valeur = '[masqué]' and entete = 'Bearer [masqué]'")
        ->and($exception->headers)->toEqual(['x-jeton' => ['[masqué]'], 'x-transmis' => ['Bearer [masqué]'], 'authorization' => '[masqué]', '[masqué]' => ['clef']])
        ->and($exception->body)->toEqual([
            'note' => 'jeton [masqué]',
            'profond' => ['a' => ['b' => ['Bearer [masqué]']]],
            '[masqué]' => 'clef',
            'components' => [['snapshot' => $instantane]],
        ])
        ->and(json_decode(is_string($instantane) ? $instantane : '', true))->toBe(['data' => ['note' => 'Bearer [masqué]', 'jeton' => '[masqué]']])
        ->and($exception->cookies)->toBeNull()
        ->and($exception->route_context)->toEqual(['controller' => 'Closure', 'route name' => 'diagnostic.etat', 'middleware' => 'jeton [masqué]'])
        ->and($exception->route_parameters)->toEqual(['reste' => '[masqué]', 'entete' => 'Bearer [masqué]'])
        ->and($exception->markdown)->toBe("# RuntimeException\n\nJeton refusé : [masqué]\n\n## Headers\n\n* **x-transmis**: Bearer [masqué]\n");
});

/*
 * Chaque texte est un peu plus long que son budget, et finit par le jeton :
 * coupé avant d'être masqué, il garderait le préfixe, l'identifiant et le
 * début du secret.
 */
it('masque le jeton avant de couper une colonne à son budget', function (): void {
    $jeton = jetonsHorsDesExceptionsJeton();

    ExceptionEnregistree::query()->create([
        'type' => RuntimeException::class,
        'code' => '0',
        'message' => str_repeat('x', 8_150).' '.$jeton,
        'file' => '/app/x.php',
        'line' => 1,
        'trace' => [['file' => '/app/x.php', 'line' => 1, 'function' => 'f', 'args' => [['string', str_repeat('z', 990).' '.$jeton]]]],
        'method' => 'POST',
        'path' => '_boum',
        'body' => ['note' => str_repeat('y', 990).' '.$jeton],
        'markdown' => str_repeat('w', 24_540).' '.$jeton,
    ]);

    expect(jetonsHorsDesExceptionsColonnesQuiLesLaissentLire())->toBe([]);

    $exception = ExceptionEnregistree::query()->sole();

    expect($exception->message)->toBe(str_repeat('x', 8_150).' [masqué]')
        ->and($exception->trace[0]['args'] ?? null)->toBe([['string', str_repeat('z', 990).' [masqué]']])
        ->and($exception->body)->toBe(['note' => str_repeat('y', 990).' [masqué]'])
        ->and($exception->markdown)->toBe(str_repeat('w', 24_540).' [masqué]');
});

/*
 * Seuls le jeton entier et ce qui suit `Bearer` et un blanc sont masqués : un
 * nom de méthode ou de classe, `Bearer` seul ou suivi d'une ponctuation, un
 * texte qui n'a pas la forme entière du jeton, une empreinte, une suite
 * hexadécimale ou un nom de migration restent lisibles dans chaque colonne.
 * L'expurgation du diagnostic masque aussi les longues suites, mais pas la
 * table, où une empreinte ou un fichier du build se lisent.
 */
it('laisse lire, dans chaque colonne, ce qui ressemble au jeton ou à une valeur Bearer sans en être', function (): void {
    $empreinte = hash('sha256', 'erreur navigateur');
    $presque = 'gtdiag_'.jetonsHorsDesExceptionsIdentifiant().'_'.substr(jetonsHorsDesExceptionsSecret(), 1);
    $texte = 'Request::bearerToken() rend null ; BearerTokenGuard refuse ; token_type Bearer, format Bearer <jeton> ; '
        ."gtdiag_court, {$presque}, gtdiag_".strtoupper(jetonsHorsDesExceptionsIdentifiant()).'_'.jetonsHorsDesExceptionsSecret()
        ." ; empreinte {$empreinte} ; migration 2026_09_06_115255_create_filament_exceptions_table ; sans Bearer";

    $colonnes = [
        'message' => $texte,
        'trace' => [['file' => '/app/vendor/laravel/framework/src/Illuminate/Http/Concerns/InteractsWithInput.php', 'line' => 1, 'class' => 'Laravel\Sanctum\Guard', 'function' => 'bearerToken', 'args' => [['string', $texte]]]],
        'path' => "diagnostic/v1/erreurs-navigateur/{$empreinte}",
        'query' => [['connectionName' => 'mysql', 'sql' => "select * from erreurs_navigateur where empreinte = '{$empreinte}'", 'time' => 1.0]],
        'headers' => ['x-note' => [$texte], 'user-agent' => ['Mozilla/5.0 (X11; Linux x86_64)']],
        'body' => ['note' => $texte, 'token_type' => 'Bearer', 'reponse' => '{"token_type":"Bearer","expires_in":3600}'],
        'route_context' => ['controller' => 'App\Http\Controllers\Api\V1\SanteController@show', 'route name' => 'diagnostic.etat'],
        'route_parameters' => ['empreinte' => $empreinte, 'note' => $texte],
        'markdown' => "# RuntimeException\n\n{$texte}\n",
    ];

    ExceptionEnregistree::query()->create([
        'type' => RuntimeException::class,
        'code' => '0',
        'file' => '/app/x.php',
        'line' => 1,
        'method' => 'GET',
        ...$colonnes,
    ]);

    $exception = ExceptionEnregistree::query()->sole();

    foreach ($colonnes as $colonne => $valeur) {
        expect($exception->getAttribute($colonne))->toEqual($valeur, "{$colonne} a été masquée à tort");
    }
});

/*
 * Le motif de `Bearer` lit des octets : un secret de plus de 4 096 octets en
 * caractères accentués est coupé au milieu d'un caractère. Le reste de la
 * valeur commencerait alors par un octet que ni MySQL ni le JSON n'acceptent,
 * et le paquet perdrait l'exception sans rien dire.
 */
it('garde l’exception quand le masque d’une valeur Bearer coupe un caractère', function (): void {
    $longue = 'Bearer a'.str_repeat('é', 3_000);

    ExceptionEnregistree::query()->create([
        'type' => RuntimeException::class,
        'code' => '0',
        'message' => $longue,
        'file' => '/app/x.php',
        'line' => 1,
        'trace' => [],
        'method' => 'POST',
        'path' => '_boum',
        'headers' => ['x-transmis' => [$longue]],
    ]);

    $exception = ExceptionEnregistree::query()->sole();
    $entete = data_get($exception->headers, 'x-transmis.0');

    expect($exception->message)->toBe('Bearer [masqué]?'.str_repeat('é', 952))
        ->and($entete)->toBeString()->toStartWith('Bearer [masqué]?é')
        ->and(mb_check_encoding(is_string($entete) ? $entete : '', 'UTF-8'))->toBeTrue();
});

/*
 * Le chemin réel : le paquet rapporte l'exception d'une requête qui porte le
 * jeton dans l'en-tête `Authorization`, un autre en-tête, la chaîne de
 * requête, le corps, le chemin et une requête SQL, et dont le message et la
 * pile le recopient. La pile garde ses arguments, comme hors de l'image.
 * Avec `APP_DEBUG`, celui de la CI (`.ai/rules/tests.md`), les requêtes SQL
 * et le Markdown, qui cite les en-têtes et le chemin, sont remplis aussi, et
 * vérifiés de même ; sans lui, ils restent vides.
 */
it('masque le jeton et la valeur Bearer qu’une vraie requête recopie dans l’exception', function (): void {
    ini_set('zend.exception_ignore_args', '0');

    $jeton = jetonsHorsDesExceptionsJeton();
    $porteur = 'Bearer '.jetonsHorsDesExceptionsSecretDuPorteur();

    Route::post('/_boum/{reste}', static function (Request $requete): never {
        DB::select('select ? as jeton', [$requete->string('jeton')->toString()]);

        $verifier = static function (string $jetonRecu, string $entete): never {
            throw new RuntimeException("Jeton refusé : {$jetonRecu}, en-tête {$entete}");
        };

        $verifier($requete->string('jeton')->toString(), (string) $requete->headers->get('X-Transmis'));
    });

    $this->withHeaders(['Authorization' => 'Bearer '.$jeton, 'X-Transmis' => $porteur, 'X-Jeton' => $jeton])
        ->post("/_boum/{$jeton}?jeton={$jeton}", ['note' => $porteur, 'profond' => ['jeton' => $jeton]])
        ->assertStatus(500);

    expect(jetonsHorsDesExceptionsColonnesQuiLesLaissentLire())->toBe([]);

    $exception = ExceptionEnregistree::query()->sole();

    expect($exception->message)->toBe('Jeton refusé : [masqué], en-tête Bearer [masqué]')
        ->and($exception->path)->toBe('_boum/[masqué]')
        ->and($exception->route_parameters)->toBe(['reste' => '[masqué]'])
        ->and($exception->body)->toEqual(['jeton' => '[masqué]', 'note' => 'Bearer [masqué]', 'profond' => ['jeton' => '[masqué]']])
        ->and($exception->headers['authorization'] ?? null)->toBe('[masqué]')
        ->and($exception->headers['x-transmis'] ?? null)->toBe('Bearer [masqué]')
        ->and($exception->headers['x-jeton'] ?? null)->toBe('[masqué]')
        ->and((string) json_encode($exception->trace, JSON_UNESCAPED_UNICODE))->toContain('[["string","[masqué]"],["string","Bearer [masqué]"]]');

    if (config()->boolean('app.debug')) {
        expect((string) $exception->markdown)->toContain('* **authorization**: Bearer [masqué]')
            ->and((string) $exception->markdown)->toContain('POST /_boum/[masqué]')
            ->and((string) json_encode($exception->query, JSON_UNESCAPED_UNICODE))->toContain("select '[masqué]' as jeton");
    }
});
