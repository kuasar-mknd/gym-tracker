<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Expurgation;

/**
 * Cinquième étape de l'expurgation : les valeurs connues de la configuration
 * remplacées par une marque qui dit ce qu'elles étaient.
 *
 * Hôte de l'application ; hôtes, bases et utilisateurs des connexions ; hôte
 * et utilisateur du courrier ; hôte du cache ; dossier des sauvegardes. Ce
 * sont les noms que les messages d'erreur citent le plus (une connexion
 * refusée, un envoi qui échoue), et ceux qui disent le mieux comment la
 * production est faite. Ils sont relus à chaque appel, jamais gardés : sous
 * Octane, une configuration changée vaut dès la requête suivante. Une valeur
 * de moins de quatre caractères n'est pas cherchée, pour ne pas effacer les
 * mots qui la contiennent ; elle n'est remplacée qu'entière, jamais au milieu
 * d'un mot ou d'un nom plus long, sans tenir compte de la casse.
 */
final class MarqueLesValeursDeLaConfiguration
{
    public const int LONGUEUR_MIN = 4;

    public function __invoke(string $texte): string
    {
        return array_reduce(
            $this->valeurs(),
            static fn (string $partiel, array $valeur): string => Motifs::remplacer(
                '/(?<![\w.-])'.preg_quote($valeur[0], '/').'(?![\w-])/iu',
                $valeur[1],
                $partiel,
            ),
            $texte,
        );
    }

    /**
     * Chaque valeur cherchée et sa marque, les plus longues d'abord : un
     * hôte qui en contient un autre est remplacé entier.
     *
     * @return list<array{string, string}>
     */
    private function valeurs(): array
    {
        $valeurs = [];

        foreach ($this->marques() as $marque => $candidates) {
            $cherchees = array_filter($candidates, static fn (mixed $valeur): bool => is_string($valeur) && mb_strlen($valeur) >= self::LONGUEUR_MIN);
            $valeurs = [...$valeurs, ...array_map(static fn (string $valeur): array => [$valeur, $marque], $cherchees)];
        }

        usort($valeurs, static fn (array $une, array $autre): int => mb_strlen($autre[0]) <=> mb_strlen($une[0]));

        return $valeurs;
    }

    /**
     * @return array<string, array<array-key, mixed>>
     */
    private function marques(): array
    {
        $connexions = (array) config('database.connections', []);

        return [
            '[hôte de l’application]' => [parse_url(config()->string('app.url'), PHP_URL_HOST)],
            '[hôte de la base]' => array_column($connexions, 'host'),
            '[base]' => array_column($connexions, 'database'),
            '[utilisateur de la base]' => array_column($connexions, 'username'),
            '[hôte du cache]' => [config('database.redis.default.host'), config('database.redis.cache.host')],
            '[hôte du courrier]' => [config('mail.mailers.smtp.host')],
            '[utilisateur du courrier]' => [config('mail.mailers.smtp.username')],
            '[dossier des sauvegardes]' => [config('filesystems.disks.sauvegardes.root')],
        ];
    }
}
