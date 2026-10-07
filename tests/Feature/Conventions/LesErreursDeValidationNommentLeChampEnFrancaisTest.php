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

/*
 * Un message composé à la main échappe aux règles que la garde lit : le
 * modèle de séance vérifie ses exercices hors des règles et passait un nom
 * anglais en dur, « Le champ exercise id sélectionné est invalide. » (#1975).
 * Un message de app/ qui reprend une phrase du validateur nomme donc le champ
 * par `$validator->getDisplayableAttribute()`, qui lit `attributes`, et non
 * par un texte écrit à côté.
 */
const VALIDATION_FRANCAISE_ATTRIBUT_EN_DUR = '/\b(?:__|trans|trans_choice|Lang::get)\(\s*[\'"]validation\.[^\'"]+[\'"]\s*,\s*\[[^\]]*[\'"]attribute[\'"]\s*=>\s*[\'"]/u';

it('ne passe aucun nom de champ écrit en dur à une phrase du validateur', function (): void {
    expect(preg_match(VALIDATION_FRANCAISE_ATTRIBUT_EN_DUR, "__('validation.exists', ['attribute' => 'exercise id'])"))->toBe(1)
        ->and(preg_match(VALIDATION_FRANCAISE_ATTRIBUT_EN_DUR, "__('validation.exists', ['attribute' => \$validator->getDisplayableAttribute(\$cle)])"))->toBe(0);

    $enDur = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $fichier) {
        $source = $fichier->getContents();

        if (preg_match_all(VALIDATION_FRANCAISE_ATTRIBUT_EN_DUR, $source, $trouves, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($trouves[0] as [, $position]) {
            $enDur[] = 'app/'.$fichier->getRelativePathname().':'.(substr_count(substr($source, 0, $position), "\n") + 1);
        }
    }

    expect($enDur)->toBe([], 'nommez le champ par $validator->getDisplayableAttribute() : un nom écrit en dur contourne lang/fr/validation.php');
});

/*
 * Une règle maison écrit son propre message, que les gardes précédentes
 * laissent de côté : celle de l'abonnement push disait « L'endpoint doit être
 * une URL https. », et le profil affiche ce message tel quel (#1975). Un
 * `$fail('…')` de app/ nomme donc le champ par `:attribute`, que
 * `lang/fr/validation.php` traduit, et ne cite jamais une clef de règle dont
 * le nom français diffère (`endpoint`, `target_value` ou `target value`).
 * La casse compte, à la majuscule initiale près : « URL » est un mot du
 * message, `url` une clef.
 */
const VALIDATION_FRANCAISE_MESSAGE_DE_REGLE_MAISON = '/\$fail\(\s*([\'"])((?:\\\\.|(?!\1)[^\\\\])*)\1/u';

/**
 * Les clefs qui sont aussi des mots français, qu'un message peut écrire sans
 * citer le champ : « une part », « content », « les types ».
 */
const VALIDATION_FRANCAISE_CLEFS_QUI_SONT_DES_MOTS = ['agent', 'content', 'label', 'part', 'pile', 'types'];

/**
 * Les clefs de règle qu'un message ne doit pas citer : le dernier segment de
 * chaque clef validée par app/Http/Requests, sous sa forme brute et avec des
 * espaces, quand son nom français est un autre mot (`date` reste `date`).
 *
 * @return list<string>
 */
function validationFrancaiseClefsACiterParLeurNom(): array
{
    $formes = [];

    foreach (validationFrancaiseRequetes() as $requete) {
        foreach (array_keys(validationFrancaiseRegles($requete)) as $clef) {
            $segments = array_values(array_filter(explode('.', (string) $clef), static fn (string $segment): bool => $segment !== '*' && ! ctype_digit($segment)));
            $segment = end($segments);
            $nom = validationFrancaiseNomDuChamp((string) $clef, $requete);

            if ($segment === false || $nom === null || mb_strtolower($nom) === str_replace('_', ' ', $segment) || in_array($segment, VALIDATION_FRANCAISE_CLEFS_QUI_SONT_DES_MOTS, true)) {
                continue;
            }

            $formes[] = $segment;
            $formes[] = str_replace('_', ' ', $segment);
        }
    }

    $formes = array_values(array_unique($formes));
    sort($formes);

    return $formes;
}

it('ne cite aucune clef de règle dans le message d’une règle maison', function (): void {
    $formes = validationFrancaiseClefsACiterParLeurNom();
    $citeUneClef = static fn (string $message): ?string => array_find(
        $formes,
        static fn (string $forme): bool => preg_match('/(?<![\p{L}:_])(?:'.preg_quote($forme, '/').'|'.preg_quote(ucfirst($forme), '/').')(?![\p{L}_])/u', $message) === 1,
    );

    expect($formes)->toContain('endpoint', 'target_value', 'target value')
        ->and($citeUneClef('L\'endpoint doit être une URL https.'))->toBe('endpoint')
        ->and($citeUneClef('Endpoint invalide.'))->toBe('endpoint')
        ->and($citeUneClef('La target value est trop grande.'))->toBe('target value')
        ->and($citeUneClef('Le champ :attribute doit être une URL https.'))->toBeNull()
        ->and($citeUneClef('Le champ :attribute ne dépasse pas :value.'))->toBeNull();

    $citations = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $fichier) {
        $source = $fichier->getContents();

        if (preg_match_all(VALIDATION_FRANCAISE_MESSAGE_DE_REGLE_MAISON, $source, $trouves, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($trouves as $trouve) {
            $message = stripslashes($trouve[2][0]);
            $clef = $citeUneClef($message);

            if ($clef !== null) {
                $citations[] = 'app/'.$fichier->getRelativePathname().':'.(substr_count(substr($source, 0, $trouve[0][1]), "\n") + 1)." cite « {$clef} »";
            }
        }
    }

    expect($citations)->toBe([], 'nommez le champ par :attribute : le message afficherait la clef anglaise');
});
