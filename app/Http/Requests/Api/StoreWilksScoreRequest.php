<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\StoreWilksScoreRequest as ValidationPartagee;

/**
 * Les memes regles que le chemin web, sans rien de plus.
 *
 * Elles divergeaient sur deux points, et les deux comptaient (#1378).
 *
 * Les bornes, d'abord : le web limitait le poids de corps a 500 kg et la
 * charge a 1000, l'API se contentait de `gt:0`. Une contrainte presente d'un
 * cote et absente de l'autre est contournable en changeant de porte d'entree.
 *
 * `score`, ensuite, et c'est le plus grave : l'API l'EXIGEAIT du client et
 * l'enregistrait tel quel, alors que le chemin web le CALCULE — conversion
 * d'unites comprise — dans `CreateWilksScoreAction`. Un historique constitue
 * par l'API etait donc de la fiction : le score de force relative valait ce
 * que l'appelant declarait. Le champ n'est plus accepte, et le controleur
 * passe par la meme action que le web.
 *
 * Par heritage plutot que par copie, sur le modele de
 * `Api\DailyJournalStoreRequest` : une regle durcie d'un cote l'est des deux,
 * sans que personne ait a y penser. La classe est vide EXPRES — le jour ou
 * l'API a besoin d'une regle qui lui est propre, elle s'ajoute ici par-dessus
 * `parent::rules()`, comme le fait le journal.
 */
class StoreWilksScoreRequest extends ValidationPartagee
{
}
