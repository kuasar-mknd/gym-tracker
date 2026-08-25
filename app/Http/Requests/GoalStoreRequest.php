<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Goal;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class GoalStoreRequest extends FormRequest
{
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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:weight,frequency,volume,measurement'],
            'target_value' => ['required', 'numeric', 'min:0'],
            'exercise_id' => [
                'required_if:type,weight,volume',
                'nullable',
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
                ? ['nullable', 'date']
                : ['nullable', 'date', 'after:today'],
            'start_value' => ['nullable', 'numeric'],
        ];
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
}
