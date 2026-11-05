<?php

declare(strict_types=1);

use App\Http\Requests\Api\WorkoutFinishRequest;
use App\Http\Requests\Api\WorkoutStoreRequest;
use App\Http\Requests\Concerns\RameneLesDatesAuFuseauDeLApplication;
use App\Models\Casts\InstantAvecSonDecalage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
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
 * exige l'instant ou le jour de l'application : l'instant à l'heure murale,
 * seule ou suivie du décalage de l'application à cet instant.
 *
 * Un champ qui porte le nom d'un instant écrit avec son décalage (un attribut
 * casté par `InstantAvecSonDecalage` dans un modèle de app/Models : le début
 * et la fin d'une séance et d'un jeûne, #2159) n'a droit qu'à la seconde
 * forme, `Y-m-d H:i:sP` : l'heure seule s'y relirait dans la seconde
 * occurrence de l'heure que le passage à l'heure d'hiver répète. Font
 * exception, nommés dans `datesRecuesSansDecalageVoulu()`, les champs dont
 * seul l'âge compte et qui ne s'écrivent nulle part.
 *
 * Elle ne voit pas :
 *
 * - un champ `date_format`, dont la conversion casserait le format exigé ;
 * - une validation écrite hors d'une requête de formulaire (`$request->validate()`
 *   dans un contrôleur, un formulaire du panneau, qui gère son fuseau) ;
 * - un champ d'un tableau (`lignes.*.date`) : elle le signale plutôt que de le
 *   croire couvert ;
 * - un champ qui part vers un instant écrit avec son décalage sous un autre
 *   nom que l'attribut : elle le reconnaît à son nom.
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
 * Les attributs qu'un modèle de app/Models écrit avec leur décalage
 * (`InstantAvecSonDecalage`), lus dans ses casts.
 *
 * @return list<string>
 */
function datesRecuesInstantsAvecDecalage(): array
{
    $attributs = [];

    foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php')->notPath(['Casts', 'Traits']) as $fichier) {
        $classe = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $fichier->getRelativePathname());

        if (! is_subclass_of($classe, Model::class) || new ReflectionClass($classe)->isAbstract()) {
            continue;
        }

        foreach (new $classe()->getCasts() as $attribut => $cast) {
            if (is_string($cast) && str_starts_with($cast, InstantAvecSonDecalage::class.':')) {
                $attributs[] = $attribut;
            }
        }
    }

    return array_values(array_unique($attributs));
}

/**
 * Les champs qui portent le nom d'un instant écrit avec son décalage sans
 * aller vers sa colonne : seul l'âge du geste y compte (`MesureLAgeDuGeste`),
 * mesuré sur la valeur reçue avant qu'elle ne soit ramenée, et l'action place
 * l'instant sur sa propre lecture de l'horloge (`Workout::debutBorne()`,
 * `finBornee()`) ; l'heure reçue ne s'écrit nulle part (`.ai/rules/horloge.md`).
 *
 * @return array<class-string<FormRequest>, list<string>>
 */
function datesRecuesSansDecalageVoulu(): array
{
    return [
        WorkoutStoreRequest::class => ['started_at'],
        WorkoutFinishRequest::class => ['ended_at'],
    ];
}

/**
 * Vrai quand le champ de cette requête doit garder son décalage : il porte le
 * nom d'un instant écrit avec lui, et n'est pas une exception nommée.
 *
 * @param  class-string<FormRequest>  $classe
 */
function datesRecuesGardeSonDecalage(string $classe, string $champ): bool
{
    return in_array($champ, datesRecuesInstantsAvecDecalage(), true)
        && ! in_array($champ, datesRecuesSansDecalageVoulu()[$classe] ?? [], true);
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
 * Un champ qui garde son décalage n'a droit qu'à `Y-m-d H:i:sP`.
 */
function datesRecuesEcart(FormRequest $requete, string $champ, bool $gardeSonDecalage = false): ?string
{
    if (str_contains($champ, '*')) {
        return 'champ daté dans un tableau : la garde ne sait pas le vérifier, étends-la';
    }

    (fn () => $this->prepareForValidation())->call($requete);

    $instant = Carbon::parse('2026-10-04T22:30:00Z')->setTimezone(config()->string('app.timezone'));
    $attendues = $gardeSonDecalage
        ? [$instant->format('Y-m-d H:i:sP')]
        : [$instant->format('Y-m-d H:i:s'), $instant->format('Y-m-d H:i:sP'), $instant->format('Y-m-d')];
    $recue = $requete->input($champ);
    $ecrite = is_scalar($recue) ? (string) $recue : get_debug_type($recue);

    if (in_array($recue, $attendues, true)) {
        return null;
    }

    return $gardeSonDecalage
        ? sprintf("instant écrit avec son décalage : reçoit « %s », la requête rend « %s » au lieu de « %s », que le modèle relirait dans la seconde occurrence de l'heure répétée", '2026-10-04T22:30:00Z', $ecrite, $attendues[0])
        : sprintf("reçoit « %s », l'application écrirait « %s »", '2026-10-04T22:30:00Z', $ecrite);
}

it('trouve des champs datés à vérifier', function (): void {
    expect(datesRecuesChampsParRequete())
        ->toHaveKey(App\Http\Requests\UpdateWorkoutRequest::class)
        ->toHaveKey(App\Http\Requests\StoreWaterLogRequest::class)
        ->and(datesRecuesInstantsAvecDecalage())->toEqualCanonicalizing(['started_at', 'ended_at', 'start_time', 'end_time']);
});

it('ramène au fuseau de l’application chaque champ validé par date', function (): void {
    $fautes = [];

    foreach (datesRecuesChampsParRequete() as $classe => $champs) {
        foreach ($champs as $champ) {
            $ecart = datesRecuesEcart(datesRecuesPreparer($classe, [$champ => '2026-10-04T22:30:00Z']), $champ, datesRecuesGardeSonDecalage($classe, $champ));

            if ($ecart !== null) {
                $fautes[] = "{$classe} › {$champ} : {$ecart}";
            }
        }
    }

    expect($fautes)->toBe([], "une date reçue avec un décalage serait écrite à l'heure du décalage, relue à celle de l'application :\n  "
        .implode("\n  ", $fautes)
        ."\n  Appeler ramenerLesInstantsAuFuseauDeLApplication(), ramenerLesInstantsAuFuseauDeLApplicationAvecLeurDecalage() ou ramenerLesJoursAuFuseauDeLApplication() dans prepareForValidation() ;"
        ."\n  la deuxième pour un instant écrit avec son décalage, sauf un champ dont seul l'âge compte, à nommer dans datesRecuesSansDecalageVoulu().");
});

it('ne garde que des exceptions qui portent le nom d’un instant écrit avec son décalage, validé par date dans leur requête', function (): void {
    $champsDates = datesRecuesChampsParRequete();
    $perimees = [];

    foreach (datesRecuesSansDecalageVoulu() as $classe => $champs) {
        foreach ($champs as $champ) {
            if (! in_array($champ, $champsDates[$classe] ?? [], true) || ! in_array($champ, datesRecuesInstantsAvecDecalage(), true)) {
                $perimees[] = "{$classe} › {$champ}";
            }
        }
    }

    expect($perimees)->toBe([], "exception qui ne sert plus, à retirer de datesRecuesSansDecalageVoulu() :\n  ".implode("\n  ", $perimees));
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

    $avecDecalage = new class() extends FormRequest
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
            $this->ramenerLesInstantsAuFuseauDeLApplicationAvecLeurDecalage(['quand']);
        }
    };

    $requeteAvecDecalage = datesRecuesPreparer($avecDecalage::class, ['quand' => '2026-10-04T22:30:00Z']);

    expect(datesRecuesEcart($requeteAvecDecalage, 'quand'))->toBeNull()
        ->and($requeteAvecDecalage->input('quand'))->toBe('2026-10-05 00:30:00+02:00');

    /*
     * Un instant écrit avec son décalage ne se contente pas de l'heure seule :
     * ramené par la méthode des instants sans décalage, le début d'une séance
     * est refusé ; avec son décalage, il passe.
     */
    $debutSansDecalage = new class() extends FormRequest
    {
        use RameneLesDatesAuFuseauDeLApplication;

        /** @return array<string, list<string>> */
        public function rules(): array
        {
            return ['started_at' => ['required', 'date']];
        }

        #[\Override]
        protected function prepareForValidation(): void
        {
            $this->ramenerLesInstantsAuFuseauDeLApplication(['started_at']);
        }
    };

    $debutAvecDecalage = new class() extends FormRequest
    {
        use RameneLesDatesAuFuseauDeLApplication;

        /** @return array<string, list<string>> */
        public function rules(): array
        {
            return ['started_at' => ['required', 'date']];
        }

        #[\Override]
        protected function prepareForValidation(): void
        {
            $this->ramenerLesInstantsAuFuseauDeLApplicationAvecLeurDecalage(['started_at']);
        }
    };

    expect(datesRecuesGardeSonDecalage($debutSansDecalage::class, 'started_at'))->toBeTrue()
        ->and(datesRecuesGardeSonDecalage($debutSansDecalage::class, 'quand'))->toBeFalse()
        ->and(datesRecuesGardeSonDecalage(WorkoutStoreRequest::class, 'started_at'))->toBeFalse()
        ->and(datesRecuesGardeSonDecalage(WorkoutStoreRequest::class, 'ended_at'))->toBeTrue()
        ->and(datesRecuesEcart(datesRecuesPreparer($debutSansDecalage::class, ['started_at' => '2026-10-04T22:30:00Z']), 'started_at', true))->not->toBeNull()
        ->and(datesRecuesEcart(datesRecuesPreparer($debutSansDecalage::class, ['started_at' => '2026-10-04T22:30:00Z']), 'started_at'))->toBeNull()
        ->and(datesRecuesEcart(datesRecuesPreparer($debutAvecDecalage::class, ['started_at' => '2026-10-04T22:30:00Z']), 'started_at', true))->toBeNull();

    expect(datesRecuesEstUneRegleDate($sansConversion->rules()['quand']))->toBeTrue()
        ->and(datesRecuesEstUneRegleDate(['required', 'date_format:Y-m-d']))->toBeFalse()
        ->and(datesRecuesEcart(datesRecuesPreparer($sansConversion::class, ['quand' => '2026-10-04T22:30:00Z']), 'quand'))->not->toBeNull()
        ->and(datesRecuesEcart(datesRecuesPreparer($avecConversion::class, ['quand' => '2026-10-04T22:30:00Z']), 'quand'))->toBeNull()
        ->and(datesRecuesEcart(datesRecuesPreparer($avecConversion::class, []), 'lignes.*.quand'))->not->toBeNull();
});
