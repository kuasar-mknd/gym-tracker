<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkoutTemplateLineUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Autoriser ici, et pas seulement dans le controleur, parce qu'un
     * FormRequest valide APRES avoir autorise mais AVANT que le controleur ne
     * le fasse : la regle `exists` ci-dessous interroge la table des exercices,
     * et elle ne s'executait que si la ligne visee existait. Une charge
     * malformee visant la ligne d'autrui coutait donc une requete de plus
     * qu'un identifiant inconnu — le canal de #1433, deplace de la policy vers
     * la validation.
     *
     * Ferme closed : sans modele lie ou sans utilisateur, on refuse. Le
     * gardien de `bootstrap/app.php` rend ce refus indiscernable d'un 404, donc
     * l'appelant voit la meme chose qu'avant ; ce qui change est qu'il la voit
     * pour le meme prix.
     */
    public function authorize(): bool
    {
        $workoutTemplateLine = $this->route('workout_template_line');
        $user = $this->user();

        if (! $workoutTemplateLine instanceof \App\Models\WorkoutTemplateLine || ! $user instanceof \App\Models\User) {
            return false;
        }

        return $workoutTemplateLine->ownerUserId() === $user->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'exercise_id' => [
                'sometimes',
                'required',
                Rule::exists('exercises', 'id')->where(function (Builder $query): void {
                    $query->where(function (Builder $q): void {
                        $q->whereNull('user_id')
                            ->orWhere('user_id', $this->user()?->id);
                    });
                }),
            ],
            'order' => 'sometimes|integer',
        ];
    }
}
