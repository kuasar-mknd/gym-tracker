<?php

declare(strict_types=1);

namespace App\Actions\Fasting;

use App\Models\Fast;

/**
 * La fin posée sur un jeûne, sous le verrou des jeûnes du compte.
 *
 * Le jeûne est relu : celui que la base dit déjà terminé (qui n'est plus
 * `active` et porte une fin) garde sa fin et son statut. Une fin rejouée par
 * un second appareil ne déplace donc rien, et corriger la fin d'un jeûne
 * terminé est ignoré (réponse 200, rien ne change : la page n'offre pas ce
 * geste). Un jeûne repris, puis terminé de nouveau, prend sa nouvelle fin.
 */
final class FinDUnJeune
{
    /**
     * @param  array<string, mixed>  $donnees
     */
    public static function poser(Fast $fast, array $donnees): void
    {
        $termine = $fast->refresh()->status !== 'active' && $fast->end_time !== null;

        $fast->update(array_diff_key($donnees, array_filter(['end_time' => $termine, 'status' => $termine])));
    }
}
