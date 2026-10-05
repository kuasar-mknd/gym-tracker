<?php

declare(strict_types=1);

use App\Http\Requests\Api\SetOrderRequest;
use App\Http\Requests\Api\WorkoutLineOrderRequest;
use App\Http\Requests\Api\WorkoutTemplateUpdateRequest;
use App\Http\Requests\StoreWorkoutTemplateRequest;
use Illuminate\Foundation\Http\FormRequest;
use Tests\Support\ReglesDesRequetes;

/*
 * Une règle `integer` ou `numeric` qui n'a qu'un `min:` laisse passer un
 * nombre plus grand que sa colonne : MySQL le refuse, et la requête finit en
 * 500 au lieu d'un 422 sur le champ. Six requêtes le faisaient, sur des
 * colonnes `int` et `decimal(8,2)`.
 *
 * Chaque règle numérique de `app/Http/Requests` porte donc une borne haute ET
 * une borne basse (`min:`/`max:`, `between:`, `size:`, ou une liste `in:`
 * fermée), choisies métier et sous la capacité de la colonne : le docbloc de
 * la constante qui la porte dit pourquoi. Une règle qui tire sa borne
 * d'ailleurs entre dans `reglesNumeriquesExceptions()`, avec sa raison.
 * `LesBornesDesRequetesTiennentDansLaBaseTest` confronte ensuite les bornes
 * aux colonnes.
 */

/**
 * Les champs numériques qui tirent leur borne d'ailleurs, avec la raison.
 *
 * Un identifiant n'est jamais écrit tel quel : il est comparé aux lignes du
 * compte, et `integer` le tient déjà dans la plage d'un `bigint` (le
 * `FILTER_VALIDATE_INT` de PHP s'arrête à `PHP_INT_MAX`).
 *
 * @return array<class-string<FormRequest>, array<string, string>>
 */
function reglesNumeriquesExceptions(): array
{
    $identifiantDeSerie = 'identifiant comparé aux séries de la ligne : ReorderAction exige une permutation de celles qui existent';
    $identifiantDExercice = 'identifiant comparé en une requête aux exercices du compte (VerifieLesExercicesDuGabarit)';

    return [
        SetOrderRequest::class => ['sets.*' => $identifiantDeSerie],
        WorkoutLineOrderRequest::class => ['lines.*' => 'identifiant comparé aux lignes de la séance : ReorderAction exige une permutation de celles qui existent'],
        StoreWorkoutTemplateRequest::class => ['exercises.*.id' => $identifiantDExercice],
        WorkoutTemplateUpdateRequest::class => ['exercises.*.id' => $identifiantDExercice],
    ];
}

/**
 * Ce qui manque aux règles d'un champ numérique pour être borné.
 *
 * @param  list<string|object>  $regles
 * @return list<string>
 */
function reglesNumeriquesManques(array $regles): array
{
    if (! ReglesDesRequetes::estNumerique($regles)) {
        return [];
    }

    $bornes = ReglesDesRequetes::bornes($regles);
    $manques = [];

    if ($bornes['max'] === null) {
        $manques[] = 'sans borne haute (max:)';
    }

    if ($bornes['min'] === null) {
        $manques[] = 'sans borne basse (min:)';
    }

    return $manques;
}

it('borne en haut et en bas chaque règle numérique des requêtes', function (): void {
    $exceptions = reglesNumeriquesExceptions();
    $fautes = [];

    foreach (array_keys(ReglesDesRequetes::toutes()) as $requete) {
        foreach (ReglesDesRequetes::de($requete) as $champ => $regles) {
            if (isset($exceptions[$requete][$champ])) {
                continue;
            }

            foreach (reglesNumeriquesManques($regles) as $manque) {
                $fautes[] = "{$requete} : {$champ} {$manque}";
            }
        }
    }

    expect($fautes)->toBe([], "règles numériques sans borne :\n  ".implode("\n  ", $fautes));
});

it('n’excepte que des champs numériques qui existent et n’ont pas leur borne', function (): void {
    $perimees = [];

    foreach (reglesNumeriquesExceptions() as $requete => $champs) {
        $regles = ReglesDesRequetes::de($requete);

        foreach (array_keys($champs) as $champ) {
            if (! isset($regles[$champ]) || reglesNumeriquesManques($regles[$champ]) === []) {
                $perimees[] = "{$requete} : {$champ}";
            }
        }
    }

    expect($perimees)->toBe([], "exceptions sans objet :\n  ".implode("\n  ", $perimees));
});

/*
 * La garde voit le défaut d'origine : sans quoi elle ne prouverait rien.
 */
it('refuse une règle numérique sans borne haute, et accepte les formes bornées', function (): void {
    expect(reglesNumeriquesManques(['required', 'integer', 'min:1']))->toBe(['sans borne haute (max:)'])
        ->and(reglesNumeriquesManques(explode('|', 'nullable|numeric')))->toHaveCount(2)
        ->and(reglesNumeriquesManques(explode('|', 'required|integer|min:1|max:'.PHP_INT_MAX)))->toBe([])
        ->and(reglesNumeriquesManques(['integer', 'between:1,7']))->toBe([])
        ->and(reglesNumeriquesManques(['numeric', 'in:1,2,3']))->toBe([])
        ->and(reglesNumeriquesManques(['string', 'max:255']))->toBe([]);
});
