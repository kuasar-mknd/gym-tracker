<?php

declare(strict_types=1);

use App\Http\Requests\Concerns\RameneLesDatesAuFuseauDeLApplication;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rules\Date as RegleDate;
use Symfony\Component\Finder\Finder;

/*
 * Un champ validé par `date` ramène au fuseau de l'application la valeur qui
 * porte un décalage (#1952).
 *
 * La règle `date` accepte `2026-10-04T22:30:00Z`, et le cast `datetime`
 * d'Eloquent n'en écrit que l'heure murale, dans le fuseau du décalage. Deux
 * écrans envoyaient ainsi une heure en UTC, enregistrée deux heures trop tôt ;
 * les champs qui ne gardent que le jour prenaient le jour du décalage, ou
 * partaient en erreur 500. Le remède est dans `RameneLesDatesAuFuseauDeLApplication`,
 * que chaque requête appelle dans `prepareForValidation()`.
 *
 * Cette garde EXÉCUTE la préparation de chaque requête de app/Http/Requests sur
 * chacun de ses champs `date` (chaîne ou `Rule::date()`), avec 22 h 30 UTC, et
 * exige l'instant ou le jour de l'application. Elle ne voit pas :
 *
 * - un champ `date_format`, dont la conversion casserait le format exigé ;
 * - une validation écrite hors d'une requête de formulaire (`$request->validate()`
 *   dans un contrôleur, un formulaire du panneau, qui gère son fuseau) ;
 * - un champ d'un tableau (`lignes.*.date`) : elle le signale plutôt que de le
 *   croire couvert.
 */

/**
 * Les champs validés par `date`, requête par requête.
 *
 * @return array<class-string<FormRequest>, list<string>>
 */
function datesRecuesChampsParRequete(): array
{
    $champs = [];

    foreach (Finder::create()->files()->in(app_path('Http/Requests'))->name('*.php')->notPath('Concerns') as $fichier) {
        $classe = 'App\\Http\\Requests\\'.str_replace(['/', '.php'], ['\\', ''], $fichier->getRelativePathname());

        if (! is_subclass_of($classe, FormRequest::class) || new ReflectionClass($classe)->isAbstract()) {
            continue;
        }

        $requete = datesRecuesPreparer($classe, []);

        if (! method_exists($requete, 'rules')) {
            continue;
        }

        $regles = $requete->rules();
        assert(is_array($regles));

        foreach ($regles as $champ => $regle) {
            if (is_string($champ) && datesRecuesEstUneRegleDate($regle)) {
                $champs[$classe][] = $champ;
            }
        }
    }

    return $champs;
}

/**
 * Vrai quand l'une des règles du champ est `date` elle-même.
 */
function datesRecuesEstUneRegleDate(mixed $regle): bool
{
    $regles = is_string($regle) ? explode('|', $regle) : (is_array($regle) ? $regle : [$regle]);

    return array_any($regles, fn (mixed $une): bool => $une === 'date' || $une instanceof RegleDate);
}

/**
 * Une requête prête à servir hors d'une route : un compte connecté, pas de
 * ressource liée, le conteneur de l'application.
 *
 * @template T of FormRequest
 *
 * @param  class-string<T>  $classe
 * @param  array<string, mixed>  $donnees
 * @return T
 */
function datesRecuesPreparer(string $classe, array $donnees): FormRequest
{
    $requete = $classe::create('/', 'POST', $donnees);
    $requete->setContainer(app());
    $requete->setUserResolver(fn (): User => new User()->forceFill(['id' => 1]));
    $requete->setRouteResolver(fn (): null => null);

    return $requete;
}

/**
 * Ce qui cloche quand la requête reçoit 22 h 30 UTC sur ce champ, ou null.
 */
function datesRecuesEcart(FormRequest $requete, string $champ): ?string
{
    if (str_contains($champ, '*')) {
        return 'champ daté dans un tableau : la garde ne sait pas le vérifier, étends-la';
    }

    (fn () => $this->prepareForValidation())->call($requete);

    $instant = Carbon::parse('2026-10-04T22:30:00Z')->setTimezone(config()->string('app.timezone'));
    $attendues = [$instant->format('Y-m-d H:i:s'), $instant->format('Y-m-d')];
    $recue = $requete->input($champ);

    return in_array($recue, $attendues, true)
        ? null
        : sprintf("reçoit « %s », l'application écrirait « %s »", '2026-10-04T22:30:00Z', is_scalar($recue) ? (string) $recue : get_debug_type($recue));
}

it('trouve des champs datés à vérifier', function (): void {
    expect(datesRecuesChampsParRequete())
        ->toHaveKey(App\Http\Requests\UpdateWorkoutRequest::class)
        ->toHaveKey(App\Http\Requests\StoreWaterLogRequest::class);
});

it('ramène au fuseau de l’application chaque champ validé par date', function (): void {
    $fautes = [];

    foreach (datesRecuesChampsParRequete() as $classe => $champs) {
        foreach ($champs as $champ) {
            $ecart = datesRecuesEcart(datesRecuesPreparer($classe, [$champ => '2026-10-04T22:30:00Z']), $champ);

            if ($ecart !== null) {
                $fautes[] = "{$classe} › {$champ} : {$ecart}";
            }
        }
    }

    expect($fautes)->toBe([], "une date reçue avec un décalage serait écrite à l'heure du décalage, relue à celle de l'application :\n  "
        .implode("\n  ", $fautes)
        ."\n  Appeler ramenerLesInstantsAuFuseauDeLApplication() ou ramenerLesJoursAuFuseauDeLApplication() dans prepareForValidation().");
});

it('voit une requête qui laisse passer le décalage, et pas celle qui le ramène', function (): void {
    $sansConversion = new class() extends FormRequest
    {
        /** @return array<string, string> */
        public function rules(): array
        {
            return ['quand' => 'required|date'];
        }
    };

    $avecConversion = new class() extends FormRequest
    {
        use RameneLesDatesAuFuseauDeLApplication;

        /** @return array<string, list<string>> */
        public function rules(): array
        {
            return ['quand' => ['required', 'date']];
        }

        #[\Override]
        protected function prepareForValidation(): void
        {
            $this->ramenerLesInstantsAuFuseauDeLApplication(['quand']);
        }
    };

    expect(datesRecuesEstUneRegleDate($sansConversion->rules()['quand']))->toBeTrue()
        ->and(datesRecuesEstUneRegleDate(['required', 'date_format:Y-m-d']))->toBeFalse()
        ->and(datesRecuesEcart(datesRecuesPreparer($sansConversion::class, ['quand' => '2026-10-04T22:30:00Z']), 'quand'))->not->toBeNull()
        ->and(datesRecuesEcart(datesRecuesPreparer($avecConversion::class, ['quand' => '2026-10-04T22:30:00Z']), 'quand'))->toBeNull()
        ->and(datesRecuesEcart(datesRecuesPreparer($avecConversion::class, []), 'lignes.*.quand'))->not->toBeNull();
});
