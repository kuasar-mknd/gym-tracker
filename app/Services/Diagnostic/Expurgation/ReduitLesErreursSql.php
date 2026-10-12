<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Expurgation;

use App\Support\BaseDeDonnees\TexteDuServeurSansValeurs;

/**
 * Quatrième étape de l'expurgation : un message d'erreur SQL réduit à ce qui
 * le décrit, reconnu à `SQLSTATE[` n'importe où dans le texte.
 *
 * Le message est coupé à partir des détails de connexion
 * (`(Connection: …, SQL: …)`), qui citent l'hôte, la base et la requête. De
 * la requête ne restent que le verbe et la table, ajoutés à la fin. Le texte
 * du serveur perd ses valeurs par la règle même qui les tait à la source
 * (`TexteDuServeurSansValeurs`) : l'en-tête de PDO et les noms d'index ou de
 * colonne restent, les valeurs citées deviennent `'?'`. La sortie ne compte
 * pas sur la source pour l'avoir fait : une ligne écrite avant ce masque, ou
 * par une connexion qui ne le porte pas, le garde. En plus de la source, une
 * citation que rien ne ferme (un texte coupé avant sa fin) est fermée avant
 * le masque, qui la couvre alors jusqu'au bout.
 */
final class ReduitLesErreursSql
{
    private const string MARQUE = 'SQLSTATE[';

    private const string CONNEXION = '(Connection:';

    private const string APOSTROPHE = "'";

    private const string REQUETE = '/SQL: (?<verbe>[a-z]{1,16})\b[^`]{0,4096}?`(?<table>[\w$]{1,64})`/i';

    public function __invoke(string $texte): string
    {
        $debut = strpos($texte, self::MARQUE);

        if ($debut === false) {
            return $texte;
        }

        [$message, $connexion] = $this->separer(substr($texte, $debut));

        return substr($texte, 0, $debut).$this->sansValeurs($message).$this->requete($connexion);
    }

    /**
     * Le message, puis les détails de connexion qui le suivent.
     *
     * @return array{string, string}
     */
    private function separer(string $message): array
    {
        $coupure = strpos($message, self::CONNEXION);

        return match ($coupure) {
            false => [rtrim($message), ''],
            default => [rtrim(substr($message, 0, $coupure)), substr($message, $coupure)],
        };
    }

    /**
     * Le texte du serveur sans les valeurs qu'il cite. Une expression qui
     * échoue rend tout le champ illisible.
     */
    private function sansValeurs(string $message): string
    {
        $masque = TexteDuServeurSansValeurs::de($this->citationFermee($message));
        Motifs::verifierLeDernierAppel();

        return $masque;
    }

    /**
     * Le message, et l'apostrophe qui ferme sa dernière citation quand un
     * texte coupé l'a laissée ouverte : un nombre impair d'apostrophes, sans
     * apostrophe à la fin. Le serveur ferme toujours la sienne ; sans cela, ce
     * qui suit la dernière apostrophe, la fin d'une valeur, resterait.
     */
    private function citationFermee(string $message): string
    {
        return match (substr_count($message, self::APOSTROPHE) % 2 === 1 && ! str_ends_with($message, self::APOSTROPHE)) {
            true => $message.self::APOSTROPHE,
            false => $message,
        };
    }

    /**
     * Le verbe et la table de la requête citée, entre parenthèses.
     */
    private function requete(string $connexion): string
    {
        $morceaux = Motifs::trouver(self::REQUETE, $connexion);

        return match ($morceaux) {
            null => '',
            default => sprintf(' (%s `%s`)', mb_strtolower($morceaux['verbe']), $morceaux['table']),
        };
    }
}
