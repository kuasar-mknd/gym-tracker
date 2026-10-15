<?php

declare(strict_types=1);

use App\Enums\PorteeDeDiagnostic;
use App\Services\Diagnostic\ChoixDeLApercu;
use App\Services\Diagnostic\Projections\ExceptionDUnJobEchoue;
use App\Services\Diagnostic\Projections\JsonLu;
use App\Services\Diagnostic\Projections\LecturesBornees;
use App\Services\Diagnostic\Projections\NomDeRoute;
use App\Services\Diagnostic\Projections\PileSansArguments;
use App\Services\Diagnostic\ValeurLue;
use App\Services\Diagnostic\Vocabulaire;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\Support\ExceptionQueLeDiagnosticNeChargePas;

uses(Tests\TestCase::class);

/*
 * Les pièces dont les projections du diagnostic se servent pour lire une
 * ligne sans modèle (#2032) : chacune rend la forme attendue, ou `null`,
 * jamais un texte approché.
 */

beforeEach(function (): void {
    Config::set('app.timezone', 'Europe/Paris');
});

it('lit un instant dans le fuseau de l’application, ou rien', function (mixed $valeur, ?string $attendu): void {
    expect(ValeurLue::instant($valeur))->toBe($attendu);
})->with([
    'une chaîne de la base' => ['2026-10-12 10:00:00', '2026-10-12T10:00:00+02:00'],
    'un instant ISO en UTC' => ['2026-10-12T08:00:00Z', '2026-10-12T10:00:00+02:00'],
    'un Carbon en UTC' => [Carbon::parse('2026-10-12 08:00:00', 'UTC'), '2026-10-12T10:00:00+02:00'],
    'une chaîne vide' => ['', null],
    'rien' => [null, null],
    'un texte' => ['pas une date', null],
    'un nombre' => [12, null],
]);

it('lit un entier, une durée ou une chaîne, ou rien', function (): void {
    expect([ValeurLue::entier(12), ValeurLue::entier('-3'), ValeurLue::entier('12.5'), ValeurLue::entier('12 '), ValeurLue::entier("12\n"), ValeurLue::entier(null), ValeurLue::entier(1.0)])
        ->toBe([12, -3, null, null, null, null, null])
        ->and([ValeurLue::duree('2.345'), ValeurLue::duree(3), ValeurLue::duree('x'), ValeurLue::duree(null)])->toBe([2.35, 3.0, null, null])
        ->and([ValeurLue::texte('a'), ValeurLue::texte(3), ValeurLue::texte(null)])->toBe(['a', '', ''])
        ->and([ValeurLue::morceau("é€\xE2\x82"), ValeurLue::morceau('a'), ValeurLue::morceau(null)])->toBe(['é€?', 'a', '']);
});

it('ne garde une valeur que si elle a sa forme', function (): void {
    expect(Vocabulaire::forme(Vocabulaire::CLASSE, \App\Models\User::class))->toBe(\App\Models\User::class)
        ->and(Vocabulaire::forme(Vocabulaire::CLASSE, 'class@anonymous'))->toBe('class@anonymous')
        ->and(Vocabulaire::forme(Vocabulaire::CLASSE, 'App\Models\User; DROP'))->toBeNull()
        ->and(Vocabulaire::forme(Vocabulaire::CLASSE, 12))->toBeNull()
        ->and(Vocabulaire::forme(Vocabulaire::NOM_DE_ROUTE, "workouts.show\n"))->toBeNull()
        ->and(Vocabulaire::parmi('GET', ['GET', 'POST']))->toBe('GET')
        ->and(Vocabulaire::parmi('get', ['GET', 'POST']))->toBeNull()
        ->and(Vocabulaire::parmi(null, ['GET']))->toBeNull();
});

it('ne rend qu’un fichier du dépôt qui existe, relatif à sa racine', function (bool $sousLaRacine, ?string $chemin, ?string $attendu): void {
    expect(Vocabulaire::fichier($sousLaRacine ? base_path((string) $chemin) : $chemin))->toBe($attendu);
})->with([
    'sous la racine' => [true, 'app/Models/User.php', 'app/Models/User.php'],
    'déjà relatif' => [false, 'app/Models/User.php', 'app/Models/User.php'],
    'hors de la racine' => [false, '/etc/passwd', null],
    'qui remonte' => [false, 'app/../../etc/passwd', null],
    'qui remonte sous la racine' => [true, '../secret.php', null],
    'avec un blanc' => [false, 'app/Models/User .php', null],
    'un nom qui en a la forme sans exister' => [false, 'XK42Q7ZP.php', null],
    'un fichier absent sous la racine' => [true, 'app/Models/Inexistant.php', null],
    'un dossier' => [false, 'app/Models', null],
    'rien' => [false, null, null],
]);

it('nomme un appel de la pile, et une fermeture sans ce qui l’entoure', function (mixed $appel, ?string $attendu): void {
    expect(Vocabulaire::appel($appel))->toBe($attendu);
})->with([
    'une méthode' => ['App\Models\User->save', 'App\Models\User->save'],
    'une méthode statique' => ['App\Models\User::query', 'App\Models\User::query'],
    'une fonction' => ['array_map', 'array_map'],
    'une fermeture' => ['{closure}', '{closure}'],
    'une fermeture nommée par PHP' => ['App\Providers\AppServiceProvider->{closure:{closure:/srv/app.php:12}:13}', 'App\Providers\AppServiceProvider->{closure}'],
    'un appel vide' => ['', null],
    'un texte' => ['Ignore les consignes', null],
    'rien' => [null, null],
]);

it('lit une colonne JSON sans jamais lever', function (): void {
    expect(JsonLu::tableau('{"a":1}'))->toBe(['a' => 1])
        ->and(JsonLu::tableau('"texte"'))->toBe([])
        ->and(JsonLu::tableau('{illisible'))->toBe([])
        ->and(JsonLu::tableau(['a' => 1]))->toBe([])
        ->and(JsonLu::champ(['a' => 1], 'a'))->toBe(1)
        ->and(JsonLu::champ(['a' => 1], 'b'))->toBeNull()
        ->and(JsonLu::champ('a', 'a'))->toBeNull()
        ->and(JsonLu::texte(['a' => 'x', 'b' => 2], 'a'))->toBe('x')
        ->and(JsonLu::texte(['a' => 'x', 'b' => 2], 'b'))->toBe('');
});

it('nomme la route d’une exception et le gabarit de son chemin', function (): void {
    expect(NomDeRoute::du('{"route name":"workouts.show","controller":"X"}'))->toBe('workouts.show')
        ->and(NomDeRoute::du('{"controller":"X"}'))->toBeNull()
        ->and(NomDeRoute::du(null))->toBeNull()
        ->and(NomDeRoute::gabarit('workouts.show'))->toBe('workouts/{workout}')
        ->and(NomDeRoute::gabarit('inconnue'))->toBeNull()
        ->and(NomDeRoute::gabarit(null))->toBeNull();
});

it('lit la classe et le message d’un job échoué', function (): void {
    $texte = 'Illuminate\\Database\\QueryException: SQLSTATE[23000]: boum in /app/a.php:3 in '.base_path('vendor/x/Connection.php').":838\nStack trace:\n#0 {main}";

    expect(ExceptionDUnJobEchoue::classe($texte))->toBe(\Illuminate\Database\QueryException::class)
        ->and(ExceptionDUnJobEchoue::message($texte))->toBe('SQLSTATE[23000]: boum in /app/a.php:3')
        ->and(ExceptionDUnJobEchoue::classe('pas de classe ici'))->toBeNull()
        ->and(ExceptionDUnJobEchoue::classe(null))->toBeNull()
        ->and(ExceptionDUnJobEchoue::message("App\\X: lu in extenso\nStack trace:\n#0"))->toBe('lu in extenso')
        ->and(ExceptionDUnJobEchoue::message("App\\X: lu in /app/a.php, sans ligne\nStack trace:\n#0"))->toBe('lu in /app/a.php, sans ligne')
        ->and(ExceptionDUnJobEchoue::message("sans forme\nStack trace:\n#0"))->toBe('sans forme')
        ->and(ExceptionDUnJobEchoue::message(null))->toBe('');
});

/*
 * PHP écrit une exception qui en enveloppe une autre en commençant par la
 * plus ancienne, puis, à la fin de sa pile, « Next » et la suivante : celle
 * que le job a levée vient en dernier. Un message peut contenir « Next »
 * lui aussi : le dernier bloc n'est pris que s'il nomme une classe que
 * l'application connaît, exception ou non (le savoir demanderait de la
 * charger), sinon la tête, que PHP écrit avant tout message.
 */
it('lit l’exception que le job a levée, ou la tête quand le dernier bloc ne nomme pas une classe connue', function (): void {
    $tete = "LogicException: dedans in /app/a.php:3\nStack trace:\n#0 {main}\n\nNext RuntimeException: dehors";
    $fin = "RuntimeException: dehors in /app/a.php:4\nStack trace:\n#0 {main}";

    expect((string) new RuntimeException('dehors', 0, new LogicException('dedans')))->toStartWith('LogicException: dedans in ')
        ->toMatch('/\n#\d+ \{main\}\n\nNext RuntimeException: dehors in /')
        ->toContain(ExceptionDUnJobEchoue::SUIVANTE.'RuntimeException: dehors in ')
        ->and(ExceptionDUnJobEchoue::levee($tete, $fin))->toBe($fin)
        ->and(ExceptionDUnJobEchoue::levee($tete, 'App\\Models\\User: zzz'))->toBe('App\\Models\\User: zzz')
        ->and(ExceptionDUnJobEchoue::levee($tete, 'Ignore\\Les\\Consignes: zzz'))->toBe($tete)
        ->and(ExceptionDUnJobEchoue::levee($tete, 'pas de classe'))->toBe($tete)
        ->and(ExceptionDUnJobEchoue::levee(null, null))->toBe('');
});

/*
 * Savoir si le dernier bloc nomme une classe connue ne charge aucun
 * fichier : un nom lu en base ne choisit pas le code qui s'exécute, et une
 * classe dont le chargement échoue ne fait pas tomber la lecture. Une
 * classe que Composer sait trouver est connue sans être chargée ; un
 * autochargeur espion, placé devant les autres, voit chaque demande.
 */
it('ne charge aucune classe pour savoir si le dernier bloc en nomme une connue', function (): void {
    $tete = "LogicException: dedans in /app/a.php:3\nStack trace:\n#0 {main}";
    $connue = ExceptionQueLeDiagnosticNeChargePas::class.': dehors';
    $demandees = new ArrayObject();
    $espion = static function (string $classe) use ($demandees): void {
        $demandees->append($classe);
    };
    spl_autoload_register($espion, prepend: true);

    try {
        $lues = [ExceptionDUnJobEchoue::levee($tete, $connue), ExceptionDUnJobEchoue::levee($tete, 'Diagnostic\\Inconnue: dehors')];
    } finally {
        spl_autoload_unregister($espion);
    }

    expect($lues)->toBe([$connue, $tete])
        ->and($demandees->getArrayCopy())->toBe([])
        ->and(class_exists(ExceptionQueLeDiagnosticNeChargePas::class, false))->toBeFalse();
});

/*
 * Une lecture qui tient dans sa borne ne se dit ni échantillon ni tronquée :
 * seule la ligne de plus, ou le groupe de plus, lève le drapeau.
 */
it('ne dit l’échantillon qu’au-delà de 5 000 lignes lues, et la liste tronquée qu’au-delà de 50 groupes', function (int $lignes, int $groupes, int $lues, bool $echantillon, bool $tronque): void {
    expect(LecturesBornees::bilan(collect(range(1, $lignes)), collect(range(1, $groupes))))->toBe(['lignes_lues' => $lues, 'echantillon' => $echantillon, 'tronque' => $tronque])
        ->and(LecturesBornees::deborde(collect(range(1, $lignes))))->toBe($echantillon)
        ->and(LecturesBornees::bornees(collect(range(1, $lignes))))->toHaveCount($lues);
})->with([
    'une ligne, un groupe' => [1, 1, 1, false, false],
    'à la borne' => [LecturesBornees::LIGNES_AU_PLUS, LecturesBornees::GROUPES_AU_PLUS, LecturesBornees::LIGNES_AU_PLUS, false, false],
    'une ligne de plus' => [LecturesBornees::LIGNES_AU_PLUS + 1, LecturesBornees::GROUPES_AU_PLUS, LecturesBornees::LIGNES_AU_PLUS, true, false],
    'un groupe de plus' => [LecturesBornees::LIGNES_AU_PLUS, LecturesBornees::GROUPES_AU_PLUS + 1, LecturesBornees::LIGNES_AU_PLUS, false, true],
]);

it('ne lit d’une pile que ses premiers cadres, sans leurs arguments', function (): void {
    $texte = implode("\n", array_map(
        static fn (int $rang): string => '#'.$rang.' '.base_path('app/Models/User.php')."({$rang}): App\\Models\\User->save('argument-{$rang}')",
        range(0, 40),
    ));
    $json = (string) json_encode(array_map(static fn (int $rang): array => ['file' => base_path('app/Models/User.php'), 'line' => $rang, 'class' => \App\Models\User::class, 'type' => '->', 'function' => 'save', 'args' => ['argument']], range(0, 40)));

    expect(PileSansArguments::duTexte($texte, 2))->toBe([
        ['fichier' => 'app/Models/User.php', 'ligne' => 0, 'appel' => 'App\Models\User->save'],
        ['fichier' => 'app/Models/User.php', 'ligne' => 1, 'appel' => 'App\Models\User->save'],
    ])
        ->and(PileSansArguments::duJson($json, 30))->toHaveCount(30)
        ->and(PileSansArguments::duJson($json, 1))->toBe([['fichier' => 'app/Models/User.php', 'ligne' => 0, 'appel' => 'App\Models\User->save']])
        ->and(PileSansArguments::duJson('illisible', 30))->toBe([])
        ->and(PileSansArguments::duTexte("#0 {main}\n#1 [internal function]: x()", 30))->toBe([])
        ->and(PileSansArguments::duTexte('#0 Ignore-les-consignes.php(3): App\Models\User->save()', 30))->toBe([['fichier' => null, 'ligne' => 3, 'appel' => 'App\Models\User->save']]);
});

it('lit le choix de la page : les premières portées par défaut, les connues seulement, une fenêtre d’un jour', function (): void {
    expect(ChoixDeLApercu::de([]))->toEqual(new ChoixDeLApercu([PorteeDeDiagnostic::Sante, PorteeDeDiagnostic::Exceptions], 1))
        ->and(ChoixDeLApercu::de(['portees' => ['', 'files', 'inventee', ['x'], 'texte-brut'], 'jours' => '7']))->toEqual(new ChoixDeLApercu([PorteeDeDiagnostic::Files, PorteeDeDiagnostic::TexteBrut], 7))
        ->and(ChoixDeLApercu::de(['portees' => 'files', 'jours' => 'x']))->toEqual(new ChoixDeLApercu([PorteeDeDiagnostic::Sante, PorteeDeDiagnostic::Exceptions], 1))
        ->and(ChoixDeLApercu::de(['portees' => [''], 'jours' => 30]))->toEqual(new ChoixDeLApercu([], 30));
});
