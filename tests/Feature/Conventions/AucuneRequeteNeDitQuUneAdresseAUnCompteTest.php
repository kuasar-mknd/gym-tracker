<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rule;
use Symfony\Component\Finder\Finder;
use Tests\Support\ReglesDesRequetes;

/*
 * Aucune règle de validation ne dit qu'une adresse a un compte.
 *
 * L'inscription (`'unique:'.User::class`) et le changement d'adresse du
 * profil (`Rule::unique(User::class)`) refusaient une adresse prise par
 * « La valeur du champ adresse e-mail est déjà utilisée. » : n'importe qui
 * apprenait ainsi qu'une adresse avait un compte. Ils répondent désormais la
 * même chose dans les deux cas, et c'est un job qui cherche le compte
 * (`TraiterUneInscription`, `TraiterUnChangementDAdresse`). Une règle `unique`
 * ou `exists` sur la table des comptes, dans une requête ou dans une
 * validation écrite au contrôleur, rouvrirait cet indice : la suite échoue.
 * Le panneau, réservé aux administrateurs qui voient déjà tous les comptes,
 * n'est pas concerné.
 *
 * Deux lectures : les règles de chaque requête telles que Laravel les
 * applique (y compris derrière `Rule::when()`), et le texte de app/Http, qui
 * voit aussi une validation écrite à la main. La lecture du texte ne connaît
 * que les écritures de ses exemples : une autre lui échappe, et ne vaut pas
 * permission.
 */

/**
 * La table que vise une règle `unique` ou `exists` écrite en texte, ou null.
 */
function aucuneRequeteTableVisee(string $regle): ?string
{
    if (preg_match('/^(?:unique|exists):([^,]+)/i', $regle, $correspondance) !== 1) {
        return null;
    }

    $table = $correspondance[1];

    if (class_exists($table) && is_subclass_of($table, Model::class)) {
        $table = new $table()->getTable();
    }

    $parties = explode('.', $table);

    return end($parties);
}

/**
 * Les règles d'un champ, celles que `Rule::when()` cache comprises.
 *
 * @param  list<string|object>  $regles
 * @return list<string|object>
 */
function aucuneRequeteReglesDepliees(array $regles): array
{
    $depliees = [];

    foreach ($regles as $regle) {
        if ($regle instanceof ConditionalRules) {
            foreach ([...(array) $regle->rules(), ...(array) $regle->defaultRules()] as $cachee) {
                if (is_string($cachee) || is_object($cachee)) {
                    $depliees[] = $cachee;
                }
            }

            continue;
        }

        $depliees[] = $regle;
    }

    return $depliees;
}

/**
 * Ce code écrit-il une règle `unique` ou `exists` sur la table des comptes ?
 */
function aucuneRequeteEcritUneRegleSurLesComptes(string $code): bool
{
    return preg_match(
        '/[\'"|](?:unique|exists):(?:users|App\\\\{1,2}Models\\\\{1,2}User)\b'
        .'|[\'"](?:unique|exists):[\'"]\s*\.\s*(?:\\\\?App\\\\Models\\\\)?User::class'
        .'|Rule::(?:unique|exists)\(\s*(?:[\'"]users[\'"]|(?:\\\\?App\\\\Models\\\\)?User::class)'
        .'|new\s+(?:\\\\?Illuminate\\\\Validation\\\\Rules\\\\)?(?:Unique|Exists)\(\s*(?:[\'"]users[\'"]|(?:\\\\?App\\\\Models\\\\)?User::class)/',
        $code,
    ) === 1;
}

it('lit la table que vise une règle écrite en texte', function (string $regle, ?string $table): void {
    expect(aucuneRequeteTableVisee($regle))->toBe($table);
})->with([
    'la table nommée' => ['unique:users,email', 'users'],
    'le modèle nommé' => ['unique:App\\Models\\User', 'users'],
    'la connexion devant la table' => ['exists:mysql.users,id', 'users'],
    'une autre table' => ['unique:exercises', 'exercises'],
    'une autre règle' => ['email', null],
    'l’objet de Laravel, en texte' => [(string) Rule::unique(App\Models\User::class)->ignore(3), 'users'],
]);

it('reconnaît chaque écriture connue d’une règle sur la table des comptes', function (string $code, bool $ecrit): void {
    expect(aucuneRequeteEcritUneRegleSurLesComptes($code))->toBe($ecrit);
})->with([
    'la chaîne et le modèle' => ["'email' => ['required', 'unique:'.User::class],", true],
    'la chaîne et le modèle nommé en entier' => ["'email' => 'unique:'.\\App\\Models\\User::class,", true],
    'la chaîne et la table' => ["'email' => 'required|email|unique:users,email',", true],
    'l’existence par la table' => ["\$request->validate(['email' => 'exists:users']);", true],
    'l’objet par le modèle' => ['Rule::unique(User::class)->ignore($this->user()?->id),', true],
    'l’objet par la table' => ["Rule::exists('users', 'email'),", true],
    'l’objet construit' => ["new Unique('users', 'email'),", true],
    'une autre table' => ["Rule::unique('exercises')->where(fn (\$q) => \$q),", false],
    'un exercice existant' => ["Rule::exists('exercises', 'id'),", false],
    'une phrase' => ["// Aucune règle `unique` ni `exists` sur users n'est posée ici.", false],
]);

it('ne laisse aucune requête poser une règle unique ou exists sur la table des comptes', function (): void {
    $requetes = ReglesDesRequetes::toutes();
    $fautes = [];

    expect($requetes)->not->toBeEmpty('aucune requête trouvée : le test ne prouverait rien');

    foreach (array_keys($requetes) as $classe) {
        foreach (ReglesDesRequetes::de($classe) as $champ => $regles) {
            foreach (ReglesDesRequetes::textes(aucuneRequeteReglesDepliees($regles)) as $regle) {
                if (aucuneRequeteTableVisee($regle) === 'users') {
                    $fautes[] = "{$classe} ({$champ}) : {$regle}";
                }
            }
        }
    }

    expect($fautes)->toBe([], implode("\n  ", $fautes));
});

it('ne laisse aucune validation de app/Http écrire une telle règle', function (): void {
    $fautes = [];

    foreach (Finder::create()->files()->in(app_path('Http'))->name('*.php') as $fichier) {
        if (aucuneRequeteEcritUneRegleSurLesComptes((string) file_get_contents((string) $fichier->getRealPath()))) {
            $fautes[] = 'app/Http/'.$fichier->getRelativePathname();
        }
    }

    expect($fautes)->toBe([], implode("\n  ", $fautes));
});
