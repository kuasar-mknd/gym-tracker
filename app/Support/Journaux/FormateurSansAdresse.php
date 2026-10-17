<?php

declare(strict_types=1);

namespace App\Support\Journaux;

use App\Support\TexteSansAdresse;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Le formateur d'un canal du journal, dont chaque ligne sort sans adresse en
 * clair (#2076) : `JournalSansAdresse` le pose devant celui de Laravel.
 *
 * Il passe le texte que rend le formateur d'origine, message, contexte et
 * pile d'une exception compris, par `TexteSansAdresse`. C'est une défense :
 * les erreurs d'envoi ne citent plus d'adresse
 * (`CourrielleurQuiTaitLesAdresses`), mais un transport de secours de
 * Symfony écrit lui-même l'erreur de chacun de ses transports, et un paquet
 * peut écrire la sienne. Un formateur qui ne rend pas de texte (un tableau)
 * est masqué valeur par valeur.
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
     * Une ligne formatée, ou chaque texte d'un tableau formaté, sans adresse.
     */
    private static function masquer(mixed $formate): mixed
    {
        if (is_string($formate)) {
            return TexteSansAdresse::de($formate);
        }

        if (is_array($formate)) {
            array_walk_recursive($formate, static function (mixed &$valeur): void {
                $valeur = is_string($valeur) ? TexteSansAdresse::de($valeur) : $valeur;
            });
        }

        return $formate;
    }
}
