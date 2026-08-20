<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\SupplementUpdateRequest as ValidationPartagee;

/**
 * Les memes regles ET la meme autorisation que le chemin web.
 *
 * Les regles etaient une copie mot pour mot — meme motif que
 * `Api\SupplementStoreRequest`, meme correction (#1378).
 *
 * `authorize()` disparait aussi. La classe rendait `$this->user() !== null` la
 * ou son parent verifie la politique `update` sur le supplement vise : elle
 * AFFAIBLISSAIT son parent. Rien ne fuyait pour autant, et il vaut la peine de
 * dire pourquoi, sans quoi quelqu'un la remettra « par prudence » : le
 * controleur rappelle `authorize('update', $supplement)`, et surtout le
 * gardien central de `bootstrap/app.php` (#1418) rend 404 — jamais 422 — des
 * qu'une requete d'API vise une ressource que l'appelant ne peut meme pas
 * voir. Verifie : avec et sans cette redefinition, un utilisateur connecte
 * visant le supplement d'un autre recoit exactement `404 Resource not found.`,
 * charge utile valide ou non.
 *
 * C'est ce que dit ce gardien noir sur blanc : une porte qu'on ne peut pas
 * oublier vaut mieux que vingt-quatre classes de requete qui peuvent l'etre.
 * Cette redefinition etait l'une des vingt-quatre, et elle etait fausse.
 */
class SupplementUpdateRequest extends ValidationPartagee
{
}
