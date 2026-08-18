<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /*
             * `Rule::array()` remplace la fermeture maison qui etait ici.
             *
             * Elle appelait `array_keys()` sur une valeur non typee — sans
             * danger, le `bail` l'empechait de s'executer sur autre chose qu'un
             * tableau, mais c'etait deux entrees de baseline PHPStan pour une
             * regle que le cadre sait exprimer. Son message d'echec sur mesure
             * n'etait lu par personne : ni test, ni traduction, ni interface.
             */
            'preferences' => ['required', Rule::array($this->typesAutorises())],
            'preferences.*' => ['boolean'],
            'push_preferences' => ['required', 'array'],
            'push_preferences.*' => ['boolean'],
            'values' => ['nullable', 'array'],
            'values.*' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }

    /**
     * Les types de preference qu'une requete a le droit de nommer.
     *
     * @return array<int, string>
     */
    private function typesAutorises(): array
    {
        return [
            'daily_reminder',
            'workout_streak_reminder',
            'no_activity_reminder',
            'weekly_summary',
            'achievement_unlocked',
            'goal_progress',
            'personal_record',
            'training_reminder',
        ];
    }
}
