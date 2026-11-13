<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\GoalPeriod;
use App\Enums\GoalType;
use App\Http\Requests\Concerns\RameneLesDatesAuFuseauDeLApplication;
use App\Models\Goal;
use App\Rules\CibleAuMoinsEgaleAuDepart;
use App\Rules\CibleEnSeancesParSemaine;
use App\Rules\DateQueSaColonneTient;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class GoalStoreRequest extends FormRequest
{
    use RameneLesDatesAuFuseauDeLApplication;

    /**
     * Determine if the user is authorized to make this request.
     *
     * La mise a jour est autorisee ici, et non seulement dans le controleur.
     *
     * Laravel valide une requete de formulaire avant d'entrer dans la methode,
     * donc un `authorize()` laisse a `true` faisait tourner les regles avant le
     * refus. Or l'une d'elles interroge la base — `Rule::exists('exercises')` —
     * et ne s'execute que si la ressource a ete liee. Un objectif appartenant a
     * autrui coutait donc 4 requetes la ou un identifiant inconnu en coutait 3,
     * ce qui redonne au chronometre l'existence que #1418 et #1432 retirent du
     * statut, du corps et des en-tetes. Voir #1433, meme canal sur l'API.
     *
     * A la creation il n'y a pas de ressource liee, donc rien a cacher : la
     * capacite `create` reste verifiee par le controleur, qui est aussi le seul
     * a savoir pour quel utilisateur l'objectif est cree.
     */
    public function authorize(): bool
    {
        $goal = $this->route('goal');

        if (! $goal instanceof Goal) {
            return true;
        }

        return Gate::allows('update', $goal);
    }

    /**
     * La cible et le départ d'un objectif vont de zéro à `Goal::VALEUR_MAX` :
     * aucun type d'objectif n'a l'usage d'une valeur négative, ni d'une valeur
     * démesurée. `bail` devant l'échéance qui bouge : sans lui, `after:today`
     * comparait aussi une valeur que `date` refuse, et Carbon levait une
     * erreur 500 sur un nombre trop grand (#2071). De même, l'exercice passe
     * `bail` et `integer` avant `exists`, qui donnait un tableau imbriqué à
     * `whereIn()`.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:weight,frequency,volume,measurement'],
            /*
             * Hors mensuration, la cible ne descend pas sous le départ : le
             * record, la meilleure séance et le nombre de séances ne font que
             * monter (#2164). La comparaison se fait en nombres, par la règle
             * que partage le panneau, derrière `bail`.
             */
            'target_value' => [
                'bail',
                'required',
                'numeric',
                'min:0',
                'max:'.Goal::VALEUR_MAX,
                Rule::when(
                    $this->input('type') !== GoalType::Measurement->value,
                    [new CibleAuMoinsEgaleAuDepart($this->input('start_value'))],
                ),
                // « N séances par semaine » : un entier de 1 à 14.
                Rule::when($this->estHebdomadaire(), [new CibleEnSeancesParSemaine()]),
            ],
            /*
             * Absente du corps, la période ne se valide pas : la création
             * prend `total`, la modification garde celle de l'objectif
             * (`estHebdomadaire()`). Hors fréquence, `prepareForValidation()`
             * la ramène à `total`.
             */
            'period' => ['sometimes', 'required', Rule::enum(GoalPeriod::class)],
            'exercise_id' => [
                'bail',
                'required_if:type,weight,volume',
                'nullable',
                'integer',
                Rule::exists('exercises', 'id')->where(function (Builder $query): void {
                    $query->where(function (Builder $q): void {
                        $q->whereNull('user_id')->orWhere('user_id', $this->user()?->id);
                    });
                }),
            ],
            /*
             * Borne a ce qui existe reellement. La regle acceptait n'importe
             * quelle chaine, donc l'API laissait creer un objectif sur une
             * mensuration inexistante — et le calcul de progression rendait
             * alors une erreur 500 (#1454).
             */
            'measurement_type' => [
                'required_if:type,measurement',
                'nullable',
                'string',
                Rule::in(\App\Http\Controllers\GoalController::measurementTypeValues()),
            ],
            'deadline' => $this->deadlineIsUnchanged()
                ? ['bail', 'nullable', 'date', DateQueSaColonneTient::date()]
                : ['bail', 'nullable', 'date', DateQueSaColonneTient::date(), 'after:today'],
            /*
             * Un départ envoyé vide est déjà ramené à 0 (`prepareForValidation()`) :
             * il n'arrive jamais nul ici. Absent du corps, il ne se valide pas,
             * et la modification garde celui qui est en base (#2165).
             */
            'start_value' => ['sometimes', 'required', 'numeric', 'min:0', 'max:'.Goal::VALEUR_MAX],
        ];
    }

    /**
     * Un objectif de fréquence « par semaine » : la période envoyée, ou à
     * défaut celle de l'objectif qu'on modifie.
     */
    private function estHebdomadaire(): bool
    {
        if ($this->input('type') !== GoalType::Frequency->value) {
            return false;
        }

        $goal = $this->route('goal');
        $periode = $this->has('period')
            ? $this->input('period')
            : ($goal instanceof Goal ? $goal->period->value : GoalPeriod::Total->value);

        return $periode === GoalPeriod::Week->value;
    }

    /**
     * A deadline in the future is the right rule when a goal is being set, and
     * a trap on every edit afterwards: resubmitting the form resends the stored
     * date, so a goal whose deadline had passed could no longer be changed at
     * all — precisely when you want to fix it.
     *
     * The future check therefore applies only to a deadline the user is
     * actually moving. On the create route no goal is bound, so it always does.
     */
    private function deadlineIsUnchanged(): bool
    {
        $goal = $this->route('goal');

        if (! $goal instanceof Goal) {
            return false;
        }

        $submitted = $this->input('deadline');

        if ($submitted !== null && ! is_string($submitted)) {
            return false;
        }

        return $goal->deadline?->format('Y-m-d') === $submitted;
    }

    /**
     * Une échéance envoyée avec un décalage désigne le jour de Paris de cet
     * instant, et c'est ce jour que `after:today` doit juger (#1952).
     *
     * Un départ envoyé vide vaut 0, à la création comme à la modification : le
     * formulaire de l'application présente le champ comme facultatif et
     * affiche 0 quand il est vide. Seule la création le ramenait à 0, dans le
     * contrôleur ; la modification le transmettait nul à une colonne qui le
     * refuse, et répondait 500 sans rien enregistrer (#2165). Ramené avant les
     * règles, il vaut aussi 0 pour la borne de la cible.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->ramenerLesJoursAuFuseauDeLApplication(['deadline']);

        /*
         * La période ne sert qu'à la fréquence : un objectif qui change de type
         * ne garde pas la sienne. Et le départ d'un objectif « par semaine »
         * est zéro, la semaine repartant de zéro chaque lundi : il ne se tape
         * pas, et la cible se juge contre lui.
         */
        if ($this->input('type') !== GoalType::Frequency->value) {
            $this->merge(['period' => GoalPeriod::Total->value]);
        } elseif ($this->estHebdomadaire()) {
            $this->merge(['start_value' => 0]);
        }

        if ($this->has('start_value') && $this->estVide($this->input('start_value'))) {
            $this->merge(['start_value' => 0]);
        }
    }

    /**
     * Nul, ou une chaîne faite de blancs : ce que donne un champ vidé, avant
     * comme après `TrimStrings` et `ConvertEmptyStringsToNull`.
     */
    private function estVide(mixed $valeur): bool
    {
        return $valeur === null || (is_string($valeur) && trim($valeur) === '');
    }
}
