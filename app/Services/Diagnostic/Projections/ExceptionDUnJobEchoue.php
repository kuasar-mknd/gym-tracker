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
 * tête du texte (`lire()`). Un message peut contenir `\n\nNext ` lui aussi :
 * le dernier bloc n'est pris que s'il nomme une exception que l'application
 * connaît, sinon la tête, que PHP écrit avant tout message et qu'aucun ne
 * peut contrefaire (`levee()`).
 *
 * La pile de l'exception levée est ce que PHP écrit en dernier, après son
 * message : la base l'extrait après le dernier `Stack trace:` de la colonne
 * entière (`avecLaPile()`). Un message peut imiter des cadres, ou une
 * exception enveloppante suivie des siens, et dépasser ce qui est lu : rien
 * ne peut suivre la vraie pile, et ses cadres seuls sont lus.
 *
 * Chaque morceau est coupé en base, en octets et non en caractères (`é`
 * en prend deux, `€` trois) : le détail lit 16 Kio de la colonne en tout,
 * un quart pour la tête, un quart pour le dernier bloc, la moitié pour la
 * pile. Un caractère coupé en deux est réparé (`texte()`).
 *
 * La classe vient du début du bloc, le message de ce qui la suit jusqu'à la
 * pile, sans le dernier ` in /chemin:ligne` (l'endroit où l'exception est
 * née, que la pile dit déjà) : un ` in …` du message lui-même reste. Chaque
 * lecture est linéaire, sans motif paresseux à relancer à chaque position.
 * Le message n'est rendu que sous `TexteBrut`, expurgé.
 */
final class ExceptionDUnJobEchoue
{
    /** Ce que le détail lit de la colonne en tout, en octets. */
    public const int OCTETS_LUS = 16_384;

    /** Ce que le détail lit de la tête et du dernier bloc : un quart chacun. */
    public const int OCTETS_PAR_MORCEAU = self::OCTETS_LUS / 4;

    /** Ce que le détail lit de la pile : la moitié. */
    public const int OCTETS_DE_LA_PILE = self::OCTETS_LUS / 2;

    /** Ce que la liste lit de chaque morceau : assez pour la classe. */
    public const int OCTETS_DE_LA_LISTE = 512;

    /** Ce qui sépare, dans le texte, une exception de celle qu'elle enveloppe. */
    public const string SUIVANTE = "\n\nNext ";

    /** Ce qui sépare, dans le texte, une exception de sa pile. */
    public const string PILE = "\nStack trace:\n";

    private const string CLASSE = '/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,254}(?:@anonymous)?)(?::|$)/D';

    private const string APRES_LA_CLASSE = '/^[^:\n]{1,255}: (?<message>.*)$/sD';

    private const string ENDROIT = '/^ in [^\n]{1,1024}:\d{1,10}$/D';

    private const string SEPARATEUR_DE_L_ENDROIT = ' in ';

    /**
     * La lecture, avec la tête de la colonne `exception`
     * (`tete_de_l_exception`) et le bloc qui suit son dernier `\n\nNext `
     * (`fin_de_l_exception`), chacun coupé en base à `$octets` octets. Sans
     * exception enveloppée, les deux sont le même texte.
     */
    public static function lire(Builder $requete, int $octets): Builder
    {
        return $requete
            ->selectRaw('substring(cast(exception as binary), 1, ?) as tete_de_l_exception', [$octets])
            ->selectRaw('substring(substring_index(cast(exception as binary), ?, -1), 1, ?) as fin_de_l_exception', [self::SUIVANTE, $octets]);
    }

    /**
     * La lecture, avec la pile de l'exception levée
     * (`pile_de_l_exception`) : ce qui suit le dernier `Stack trace:` de la
     * colonne entière, coupé en base à `OCTETS_DE_LA_PILE` octets ; rien
     * d'un texte qui n'en a pas.
     */
    public static function avecLaPile(Builder $requete): Builder
    {
        return $requete->selectRaw(
            'case when locate(?, cast(exception as binary)) > 0 then substring(substring_index(cast(exception as binary), ?, -1), 1, ?) end as pile_de_l_exception',
            [self::PILE, self::PILE, self::OCTETS_DE_LA_PILE],
        );
    }

    /**
     * Le texte de l'exception que le job a levée : le dernier bloc s'il
     * nomme une exception que l'application connaît, sinon la tête.
     */
    public static function levee(mixed $tete, mixed $fin): string
    {
        $classe = self::classe($fin);

        return match ($classe !== null && is_a($classe, Throwable::class, true)) {
            true => self::texte($fin),
            false => self::texte($tete),
        };
    }

    /**
     * Un morceau lu en base : du texte, dont un caractère que la coupe a
     * tranché est remplacé.
     */
    public static function texte(mixed $morceau): string
    {
        return mb_scrub(ValeurLue::texte($morceau), 'UTF-8');
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
