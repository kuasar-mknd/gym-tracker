<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use App\Services\Diagnostic\ValeurLue;
use App\Services\Diagnostic\Vocabulaire;
use Illuminate\Database\Query\Builder;
use Throwable;

/**
 * L'exception d'un job échoué, telle que `failed_jobs` la garde : du texte,
 * `Classe: message in /chemin:ligne`, puis `Stack trace:` et un cadre par
 * ligne.
 *
 * Quand l'exception en enveloppe une autre, PHP écrit d'abord la plus
 * ancienne, puis `\n\nNext ` et la suivante : celle que le job a levée vient
 * en dernier, et c'est elle que le diagnostic lit, comme la table des
 * exceptions garde la classe levée. La base extrait ce dernier bloc et la
 * tête du texte, chacun coupé (`lire()`). Un message peut contenir
 * `\n\nNext ` lui aussi : le dernier bloc n'est pris que s'il nomme une
 * exception que l'application connaît, sinon la tête, que PHP écrit avant
 * tout message et qu'aucun ne peut contrefaire (`levee()`).
 *
 * La classe vient du début du bloc, le message de ce qui la suit jusqu'à la
 * pile, sans le dernier ` in /chemin:ligne` (l'endroit où l'exception est
 * née, que la pile dit déjà) : un ` in …` du message lui-même reste. Chaque
 * lecture est linéaire, sans motif paresseux à relancer à chaque position.
 * Le message n'est rendu que sous `TexteBrut`, expurgé.
 */
final class ExceptionDUnJobEchoue
{
    /** Ce que le détail lit de chaque morceau, en octets. */
    public const int OCTETS_LUS = 16_384;

    /** Ce que la liste lit de chaque morceau : assez pour la classe. */
    public const int OCTETS_DE_LA_LISTE = 512;

    /** Ce qui sépare, dans le texte, une exception de celle qu'elle enveloppe. */
    public const string SUIVANTE = "\n\nNext ";

    private const string CLASSE = '/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,254}(?:@anonymous)?)(?::|$)/D';

    private const string APRES_LA_CLASSE = '/^[^:\n]{1,255}: (?<message>.*)$/sD';

    private const string ENDROIT = '/^ in [^\n]{1,1024}:\d{1,10}$/D';

    private const string SEPARATEUR_DE_L_ENDROIT = ' in ';

    /**
     * La lecture, avec la tête de la colonne `exception`
     * (`tete_de_l_exception`) et le bloc qui suit son dernier `\n\nNext `
     * (`fin_de_l_exception`), chacun coupé en base à `$octets`. Sans
     * exception enveloppée, les deux sont le même texte.
     */
    public static function lire(Builder $requete, int $octets): Builder
    {
        return $requete
            ->selectRaw('substring(exception, 1, ?) as tete_de_l_exception', [$octets])
            ->selectRaw('substring(substring_index(exception, ?, -1), 1, ?) as fin_de_l_exception', [self::SUIVANTE, $octets]);
    }

    /**
     * Le texte de l'exception que le job a levée : le dernier bloc s'il
     * nomme une exception que l'application connaît, sinon la tête.
     */
    public static function levee(mixed $tete, mixed $fin): string
    {
        $classe = self::classe($fin);

        return match ($classe !== null && is_a($classe, Throwable::class, true)) {
            true => ValeurLue::texte($fin),
            false => ValeurLue::texte($tete),
        };
    }

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
