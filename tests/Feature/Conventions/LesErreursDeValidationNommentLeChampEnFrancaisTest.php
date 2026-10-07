<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationRuleParser;
use Symfony\Component\Finder\Finder;

/*
 * Un message de validation est le seul guide pour corriger une saisie, et il
 * nommait le champ par sa colonne : « Le champ deadline doit être une date
 * postérieure au today. » (#1975). `lang/fr/validation.php` ne traduisait que
 * des attributs génériques (nom, adresse e-mail, date…), et aucune requête ne
 * déclarait `attributes()`.
 *
 * La garde instancie chaque requête de app/Http/Requests, lit ses règles, et
 * exige pour chaque clef un nom français : dans `attributes` de
 * `lang/fr/validation.php` (clef exacte ou motif à étoile), ou dans le
 * `attributes()` de la requête. Elle exige aussi un message `custom` pour
 * toute règle de date relative (`after:today`…) : « postérieure au :date »
 * recopierait le mot anglais, et resterait agrammatical même traduit.
 *
 * Un nom français ne suffit pas quand la phrase qui l'entoure manque : la
 * locale de repli est l'anglais, et un objectif « Force » sans exercice
 * affichait « The exercice field is required when type is weight. » (#1975).
 * Chaque message du validateur a donc sa version française, chaque règle
 * employée par une requête aussi, et une règle conditionnelle (`required_if`…),
 * qui citerait la valeur brute de l'autre champ, a sa phrase dans `custom`
 * ou ses valeurs nommées dans `values`.
 */

/**
 * Chaque requête de validation de l'application, instanciée hors de toute
 * requête HTTP, avec un utilisateur pour les règles qui le lisent.
 *
 * @return array<class-string<FormRequest>, FormRequest>
 */
function validationFrancaiseRequetes(): array
{
    $requetes = [];

    foreach (Finder::create()->files()->in(app_path('Http/Requests'))->name('*.php')->notPath('Concerns') as $fichier) {
        $classe = 'App\\Http\\Requests\\'.str_replace(['/', '.php'], ['\\', ''], $fichier->getRelativePathname());

        if (! is_subclass_of($classe, FormRequest::class)) {
            continue;
        }

        $requete = $classe::createFrom(Request::create('/', 'POST'));
        $requete->setContainer(app());
        $requete->setUserResolver(static fn (): User => User::factory()->make(['id' => 1]));

        $requetes[$classe] = $requete;
    }

    return $requetes;
}

/**
 * Les règles d'une requête, telles que le validateur les reçoit.
 *
 * @return array<string, mixed>
 */
function validationFrancaiseRegles(FormRequest $requete): array
{
    /** @var array<string, mixed> $regles */
    $regles = method_exists($requete, 'rules') ? app()->call([$requete, 'rules']) : [];

    return $regles;
}

/**
 * Le nom français d'une clef de règle, ou null s'il n'y en a pas.
 *
 * Même recherche que le validateur (`FormatsMessages::getAttributeFromLocalArray`) :
 * la clef exacte, puis un motif à étoile.
 */
function validationFrancaiseNomDuChamp(string $clef, FormRequest $requete): ?string
{
    /** @var array<string, string> $propres */
    $propres = $requete->attributes();
    /** @var array<string, string> $traduits */
    $traduits = Arr::dot((array) trans('validation.attributes', [], 'fr'));

    foreach ([$propres, $traduits] as $source) {
        if (isset($source[$clef])) {
            return $source[$clef];
        }

        foreach ($source as $motif => $nom) {
            if (str_contains($motif, '*') && preg_match('#^'.str_replace('\*', '([^.]*)', preg_quote($motif, '#')).'\z#u', $clef) === 1) {
                return $nom;
            }
        }
    }

    return null;
}

/**
 * Les règles d'un champ, nommées comme le validateur cherche leur message
 * (`required_if`, `in`, `exists`…), avec leurs paramètres.
 *
 * Une règle objet qui s'écrit en texte (`Rule::in()`, `Rule::exists()`…) est
 * lue sous cette forme, comme le fait le validateur ; une règle conditionnelle
 * (`Rule::when()`) donne ses deux branches ; `Rule::enum()` cherche le message
 * `enum`. Une règle maison porte son propre message : elle est laissée de côté.
 *
 * @return list<array{string, list<string>}>
 */
function validationFrancaiseReglesNommees(mixed $regles): array
{
    $nommees = [];

    foreach (is_string($regles) ? explode('|', $regles) : (is_array($regles) ? $regles : [$regles]) as $regle) {
        if ($regle instanceof ConditionalRules) {
            $nommees = [
                ...$nommees,
                ...validationFrancaiseReglesNommees($regle->rules()),
                ...validationFrancaiseReglesNommees($regle->defaultRules()),
            ];

            continue;
        }

        if ($regle instanceof Enum) {
            $nommees[] = ['enum', []];

            continue;
        }

        if ($regle instanceof Stringable) {
            $regle = (string) $regle;
        }

        if (! is_string($regle) || $regle === '') {
            continue;
        }

        /** @var array{string, list<string>} $analysee */
        $analysee = ValidationRuleParser::parse($regle);
        $nommees[] = [Str::snake($analysee[0]), $analysee[1]];
    }

    return $nommees;
}

/**
 * Les clefs de message du validateur : celles du framework et celles de
 * lang/en, sous-clefs comprises (`min.numeric`, `password.letters`).
 *
 * @return list<string>
 */
function validationFrancaiseClefsDesMessages(): array
{
    $clefs = [];

    foreach ([base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'), lang_path('en/validation.php')] as $fichier) {
        /** @var array<string, mixed> $messages */
        $messages = require $fichier;

        $clefs = [...$clefs, ...array_keys(Arr::dot(Arr::except($messages, ['attributes', 'custom', 'values'])))];
    }

    $clefs = array_values(array_unique($clefs));
    sort($clefs);

    return $clefs;
}

it('trouve des requêtes à lire', function (): void {
    expect(count(validationFrancaiseRequetes()))->toBeGreaterThan(30);
});

it('donne un nom français à chaque champ validé par app/Http/Requests', function (): void {
    $sansNom = [];

    foreach (validationFrancaiseRequetes() as $classe => $requete) {
        foreach (array_keys(validationFrancaiseRegles($requete)) as $clef) {
            if (validationFrancaiseNomDuChamp($clef, $requete) === null) {
                $sansNom[] = class_basename($classe)." : {$clef}";
            }
        }
    }

    sort($sansNom);

    expect($sansNom)->toBe([], 'ajoutez ces clefs à « attributes » de lang/fr/validation.php : le message afficherait le nom de la colonne');
});

it('donne un message français à chaque règle de date relative', function (): void {
    $sansMessage = [];

    foreach (validationFrancaiseRequetes() as $classe => $requete) {
        foreach (validationFrancaiseRegles($requete) as $clef => $regles) {
            foreach (is_array($regles) ? $regles : (is_string($regles) ? explode('|', $regles) : []) as $regle) {
                if (! is_string($regle) || preg_match('/^(after|after_or_equal|before|before_or_equal|date_equals):(today|tomorrow|yesterday|now)$/', $regle) !== 1) {
                    continue;
                }

                $nomDeLaRegle = strtok($regle, ':');
                $propre = $requete->messages()["{$clef}.{$nomDeLaRegle}"] ?? null;
                $traduit = trans("validation.custom.{$clef}.{$nomDeLaRegle}", [], 'fr');

                if ($propre === null && $traduit === "validation.custom.{$clef}.{$nomDeLaRegle}") {
                    $sansMessage[] = class_basename($classe)." : {$clef} ({$regle})";
                }
            }
        }
    }

    expect($sansMessage)->toBe([], 'ces règles recopieraient « today » dans le message ; donnez-leur un message « custom »');
});

it('traduit en français chaque message du validateur, sans repli sur l’anglais', function (): void {
    $sansFrancais = array_values(array_filter(
        validationFrancaiseClefsDesMessages(),
        static fn (string $clef): bool => ! Lang::has("validation.{$clef}", 'fr', false),
    ));

    expect(count(validationFrancaiseClefsDesMessages()))->toBeGreaterThan(100)
        ->and($sansFrancais)->toBe([], 'ajoutez ces messages à lang/fr/validation.php : la locale de repli les afficherait en anglais');
});

it('donne un message français à chaque règle employée par app/Http/Requests', function (): void {
    $sansMessage = [];
    $sansMessageDeRegle = ['bail', 'exclude', 'exclude_if', 'exclude_unless', 'exclude_with', 'exclude_without', 'nullable', 'sometimes'];

    foreach (validationFrancaiseRequetes() as $classe => $requete) {
        foreach (validationFrancaiseRegles($requete) as $clef => $regles) {
            foreach (validationFrancaiseReglesNommees($regles) as [$nom]) {
                if (in_array($nom, $sansMessageDeRegle, true) || isset($requete->messages()["{$clef}.{$nom}"])) {
                    continue;
                }

                if (! Lang::has("validation.{$nom}", 'fr', false) && ! Lang::has("validation.custom.{$clef}.{$nom}", 'fr', false)) {
                    $sansMessage[] = class_basename($classe)." : {$clef} ({$nom})";
                }
            }
        }
    }

    expect($sansMessage)->toBe([], 'ces règles s’afficheraient en anglais ; ajoutez leur message à lang/fr/validation.php');
});

it('donne une phrase à chaque règle conditionnelle, ou un nom aux valeurs qu’elle cite', function (): void {
    $sansPhrase = [];

    foreach (validationFrancaiseRequetes() as $classe => $requete) {
        foreach (validationFrancaiseRegles($requete) as $clef => $regles) {
            foreach (validationFrancaiseReglesNommees($regles) as [$nom, $parametres]) {
                if (preg_match('/_(if|unless)$/', $nom) !== 1 || count($parametres) < 2) {
                    continue;
                }

                if (isset($requete->messages()["{$clef}.{$nom}"]) || Lang::has("validation.custom.{$clef}.{$nom}", 'fr', false)) {
                    continue;
                }

                $autre = array_shift($parametres);
                $valeursSansNom = array_filter($parametres, static fn (string $valeur): bool => ! Lang::has("validation.values.{$autre}.{$valeur}", 'fr', false));

                if ($valeursSansNom !== []) {
                    $sansPhrase[] = class_basename($classe)." : {$clef} ({$nom}:{$autre},".implode(',', $valeursSansNom).')';
                }
            }
        }
    }

    expect($sansPhrase)->toBe([], 'ces règles citeraient la valeur brute de l’autre champ ; donnez-leur un message « custom »');
});

/*
 * Le message d'une borne recopie son paramètre tel quel : `max:999.99` rendait
 * « … ne peut pas être supérieure à 999.99. », avec le point anglais, là où
 * l'application écrit 999,99 depuis #1787 (#1975). Une borne décimale a donc
 * sa propre phrase, dans le `messages()` de la requête ou dans `custom`.
 */
it('donne une phrase française à chaque borne décimale, que le message recopierait avec un point', function (): void {
    $sansPhrase = [];

    foreach (validationFrancaiseRequetes() as $classe => $requete) {
        foreach (validationFrancaiseRegles($requete) as $clef => $regles) {
            foreach (validationFrancaiseReglesNommees($regles) as [$nom, $parametres]) {
                $decimaux = array_filter($parametres, static fn (string $parametre): bool => preg_match('/^-?\d*\.\d+$/', $parametre) === 1);

                if ($decimaux === [] || isset($requete->messages()["{$clef}.{$nom}"]) || Lang::has("validation.custom.{$clef}.{$nom}", 'fr', false)) {
                    continue;
                }

                $sansPhrase[] = class_basename($classe)." : {$clef} ({$nom}:".implode(',', $parametres).')';
            }
        }
    }

    sort($sansPhrase);

    expect($sansPhrase)->toBe([], 'ces bornes s’écriraient avec un point décimal ; donnez-leur une phrase dans messages() ou « custom »');
});
