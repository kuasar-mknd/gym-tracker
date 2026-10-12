<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use App\Services\Diagnostic\Vocabulaire;

/**
 * L'exception d'un job échoué, telle que `failed_jobs` la garde : du texte,
 * `Classe: message in /chemin:ligne`, puis `Stack trace:` et un cadre par
 * ligne. Seuls les 16 premiers Kio sont lus, en base.
 *
 * La classe vient de la tête du texte, le message de ce qui la suit jusqu'à
 * la pile, sans le dernier ` in /chemin:ligne` (l'endroit où l'exception est
 * née, que la pile dit déjà) : un ` in …` du message lui-même reste. Chaque
 * lecture est linéaire, sans motif paresseux à relancer à chaque position.
 * Le message n'est rendu que sous `TexteBrut`, expurgé.
 */
final class ExceptionDUnJobEchoue
{
    public const int OCTETS_LUS = 16_384;

    private const string CLASSE = '/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,254}(?:@anonymous)?)(?::|$)/D';

    private const string APRES_LA_CLASSE = '/^[^:\n]{1,255}: (?<message>.*)$/sD';

    private const string ENDROIT = '/^ in [^\n]{1,1024}:\d{1,10}$/D';

    private const string SEPARATEUR_DE_L_ENDROIT = ' in ';

    public static function classe(mixed $texte): ?string
    {
        return Vocabulaire::forme(Vocabulaire::CLASSE, self::morceau(self::CLASSE, $texte, 1));
    }

    public static function message(mixed $texte): string
    {
        $tete = explode("\nStack trace:", is_string($texte) ? $texte : '', 2)[0];

        return self::sansLEndroit(self::morceau(self::APRES_LA_CLASSE, $tete, 'message') ?? $tete);
    }

    /**
     * Le message sans le ` in /chemin:ligne` qui le termine, s'il le
     * termine.
     */
    private static function sansLEndroit(string $message): string
    {
        $coupure = strrpos($message, self::SEPARATEUR_DE_L_ENDROIT);

        return match ($coupure !== false && self::morceau(self::ENDROIT, substr($message, $coupure), 0) !== null) {
            true => substr($message, 0, $coupure),
            false => $message,
        };
    }

    private static function morceau(string $motif, mixed $texte, int|string $groupe): ?string
    {
        $trouve = preg_match($motif, is_string($texte) ? $texte : '', $morceaux);

        return match ($trouve) {
            1 => $morceaux[$groupe] ?? null,
            default => null,
        };
    }
}
