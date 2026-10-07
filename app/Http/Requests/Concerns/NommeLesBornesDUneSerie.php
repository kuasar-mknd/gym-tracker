<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Set;

/**
 * Les messages d'une valeur de série refusée, communs à sa création et à sa
 * modification.
 *
 * La page de séance n'affiche nulle part les bornes d'une série : quand elle
 * rétablit une valeur refusée, elle cite le message que le serveur rend pour
 * ce champ (`useSaisieDeSerie.js`). Ce message nomme donc la borne, en
 * français, avec le nom du champ tel que l'utilisateur le lit.
 */
trait NommeLesBornesDUneSerie
{
    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'weight.max' => 'Une série porte au plus '.self::nombreLisible(Set::POIDS_MAX_KG).' kg.',
            'reps.max' => 'Une série compte au plus '.self::nombreLisible(Set::REPETITIONS_MAX).' répétitions.',
            'duration_seconds.max' => 'Une série dure au plus '.self::nombreLisible(intdiv(Set::DUREE_MAX_SECONDES, 3_600)).' heures.',
            'distance_km.max' => 'Une série couvre au plus '.self::nombreLisible(Set::DISTANCE_MAX_KM).' km.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'weight' => 'poids',
            'reps' => 'répétitions',
            'duration_seconds' => 'durée',
            'distance_km' => 'distance',
        ];
    }

    /**
     * Un entier écrit à la française : « 100 000 ».
     */
    private static function nombreLisible(int $nombre): string
    {
        return number_format($nombre, 0, ',', ' ');
    }
}
