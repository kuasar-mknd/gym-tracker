<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use App\Services\Diagnostic\Expurgation\Motifs;
use App\Services\Diagnostic\ValeurLue;
use App\Services\Diagnostic\Vocabulaire;

/**
 * Les premiers cadres d'une pile, chacun réduit à son fichier, sa ligne et
 * son appel : jamais ses arguments, qui recopient les valeurs passées.
 *
 * La pile d'une exception enregistrée est du JSON (`trace`), celle d'un job
 * échoué du texte (`#0 /chemin(12): Classe->méthode(…)`), celui que PHP
 * écrit après le message (`ExceptionDUnJobEchoue::avecLaPile()`). Dans les
 * deux cas, les arguments ne sont pas lus, et un fichier n'est rendu que
 * s'il est du dépôt et existe (`Vocabulaire::fichier()`).
 */
final class PileSansArguments
{
    private const string CADRE_EN_TEXTE = '/^#\d{1,4} (?<fichier>[^\n(]{1,1024})\((?<ligne>\d{1,10})\): (?<appel>[^\n(]{1,512})\(/m';

    /**
     * @return list<array{fichier: string|null, ligne: int|null, appel: string|null}>
     */
    public static function duJson(mixed $pileEnJson, int $cadres): array
    {
        return array_values(array_map(
            static fn (mixed $cadre): array => self::cadre(
                JsonLu::champ($cadre, 'file'),
                JsonLu::champ($cadre, 'line'),
                implode('', array_map(static fn (string $cle): string => JsonLu::texte($cadre, $cle), ['class', 'type', 'function'])),
            ),
            array_slice(JsonLu::tableau($pileEnJson), 0, $cadres),
        ));
    }

    /**
     * @return list<array{fichier: string|null, ligne: int|null, appel: string|null}>
     */
    public static function duTexte(string $texte, int $cadres): array
    {
        $trouves = rescue(static fn (): array => Motifs::toutes(self::CADRE_EN_TEXTE, $texte), [], report: false);

        return array_map(
            static fn (array $cadre): array => self::cadre($cadre['fichier'], $cadre['ligne'], $cadre['appel']),
            array_slice($trouves, 0, $cadres),
        );
    }

    /**
     * @return array{fichier: string|null, ligne: int|null, appel: string|null}
     */
    private static function cadre(mixed $fichier, mixed $ligne, mixed $appel): array
    {
        return [
            'fichier' => Vocabulaire::fichier($fichier),
            'ligne' => ValeurLue::entier($ligne),
            'appel' => Vocabulaire::appel($appel),
        ];
    }
}
