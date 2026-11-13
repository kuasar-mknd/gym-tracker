<?php

declare(strict_types=1);

namespace App\Actions\Fasting;

use App\Models\Fast;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * La reprise d'un jeûne terminé, sous le verrou des jeûnes du compte : elle
 * vaut démarrer un jeûne, donc le compte ne doit pas en avoir un autre en
 * cours. Une reprise ne porte pas de fin : l'envoyer ensemble est refusé sur
 * `end_time`, plutôt que d'en perdre l'un des deux en silence.
 */
final class ReprendreUnJeune
{
    public const string MESSAGE_AVEC_FIN = 'Reprendre un jeûne ne se fait pas avec une fin.';

    /**
     * @param  array<string, mixed>  $donnees
     *
     * @throws ValidationException
     */
    public static function reprendre(User $user, Fast $fast, array $donnees): void
    {
        throw_if(isset($donnees['end_time']), ValidationException::withMessages(['end_time' => self::MESSAGE_AVEC_FIN]));

        $autreEnCours = $user->fasts()
            ->where('status', 'active')
            ->whereKeyNot($fast->getKey())
            ->exists();

        throw_if($autreEnCours, ValidationException::withMessages(['base' => DemarrerUnJeuneAction::MESSAGE]));

        $fast->update($donnees);
    }
}
