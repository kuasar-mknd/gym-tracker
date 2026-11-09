<?php

declare(strict_types=1);

namespace App\Support\Journaux;

use App\Support\TexteSansAdresse;
use App\Support\TexteSansJeton;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Le formateur d'un canal du journal, dont chaque ligne sort sans adresse en
 * clair (#2076), ni jeton de diagnostic, ni valeur d'un en-tête `Bearer …`
 * (#2032) : `JournalSansAdresse` le pose devant celui de Laravel.
 *
 * Il passe le texte que rend le formateur d'origine, message, contexte et
 * pile d'une exception compris, par `TexteSansJeton`, le masque de la table
 * des exceptions, puis par `TexteSansAdresse`. Pour les adresses, c'est une
 * défense : les erreurs d'envoi n'en citent plus
 * (`CourrielleurQuiTaitLesAdresses`), mais un transport de secours de
 * Symfony écrit lui-même l'erreur de chacun de ses transports, et un paquet
 * peut écrire la sienne. Pour le jeton, le message d'une exception que la
 * table masque partait tel quel dans chaque canal. Un formateur qui ne rend
 * pas de texte (un tableau) est masqué valeur par valeur.
 */
final readonly class FormateurSansAdresse implements FormatterInterface
{
    public function __construct(private FormatterInterface $formateur)
    {
    }

    public function format(LogRecord $record): mixed
    {
        return self::masquer($this->formateur->format($record));
    }

    /**
     * @param  array<LogRecord>  $records
     */
    public function formatBatch(array $records): mixed
    {
        return self::masquer($this->formateur->formatBatch($records));
    }

    /**
     * Une ligne formatée, ou chaque texte d'un tableau formaté, sans jeton
     * ni adresse.
     */
    private static function masquer(mixed $formate): mixed
    {
        if (is_string($formate)) {
            return TexteSansAdresse::de(TexteSansJeton::de($formate));
        }

        if (is_array($formate)) {
            array_walk_recursive($formate, static function (mixed &$valeur): void {
                $valeur = is_string($valeur) ? TexteSansAdresse::de(TexteSansJeton::de($valeur)) : $valeur;
            });
        }

        return $formate;
    }
}
