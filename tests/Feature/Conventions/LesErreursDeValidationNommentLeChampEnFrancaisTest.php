<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
            foreach (is_array($regles) ? $regles : explode('|', (string) $regles) as $regle) {
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
