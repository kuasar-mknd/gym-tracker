<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Expurgation;

/**
 * Quatrième étape de l'expurgation : un message d'erreur SQL réduit à ce qui
 * le décrit, reconnu à `SQLSTATE[` n'importe où dans le texte.
 *
 * Le message est coupé à partir des détails de connexion
 * (`(Connection: …, SQL: …)`), qui citent l'hôte, la base et la requête avec
 * ses valeurs quand rien ne les a tues. De la requête ne restent que le verbe
 * et la table, ajoutés à la fin. L'en-tête de PDO (état SQL, sa description,
 * code du serveur) reste ; le texte du serveur passe par
 * `MasqueLeTexteDuServeur`, qui ne garde que les noms d'index ou de colonne.
 *
 * Cette réduction vaut quel que soit le réglage des connexions : la sortie ne
 * compte pas sur la source pour avoir déjà tu les valeurs.
 */
final class ReduitLesErreursSql
{
    private const string MARQUE = 'SQLSTATE[';

    private const string CONNEXION = '(Connection:';

    private const string ENTETE = '/^SQLSTATE\[[0-9A-Z]{5}\](?:: [^:\n]{1,128}: \d{1,6} | \[\d{1,6}\] |:? ?)/';

    private const string REQUETE = '/SQL: (?<verbe>[a-z]{1,16})\b[^`]{0,4096}?`(?<table>[\w$]{1,64})`/i';

    public function __invoke(string $texte): string
    {
        $debut = strpos($texte, self::MARQUE);

        if ($debut === false) {
            return $texte;
        }

        [$message, $connexion] = $this->separer(substr($texte, $debut));
        $entete = Motifs::trouver(self::ENTETE, $message)[0] ?? '';

        return substr($texte, 0, $debut)
            .$entete
            .new MasqueLeTexteDuServeur()(substr($message, strlen($entete)))
            .$this->requete($connexion);
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
