<?php

declare(strict_types=1);

namespace App\Support\Sante;

use Illuminate\Support\Facades\Cache;

/**
 * Ce que chaque conteneur de l'application dit de l'image qu'il exécute
 * (#1813). Un conteneur garde l'image avec laquelle il a été créé : le
 * scheduler de production a tourné des semaines sur une vieille version sans que
 * rien ne le dise. Chaque conteneur inscrit donc à son démarrage, dans le
 * cache (Redis en production, jamais la base), sa version, sa révision,
 * depuis quand il exécute cette image et la date de sa dernière annonce ;
 * `VersionsDesConteneursCheck` compare.
 */
final class AnnonceDeVersion
{
    /**
     * Les conteneurs qui exécutent l'image, dans l'ordre où la page les montre.
     *
     * @var list<string>
     */
    public const array CONTENEURS = ['app', 'worker', 'scheduler'];

    /**
     * Inscrit l'annonce du conteneur. Une panne du cache ne coûte que
     * l'annonce : un écouteur qui lèverait empêcherait Octane de servir ou
     * Horizon de travailler.
     */
    public static function annoncer(string $conteneur): void
    {
        rescue(static function () use ($conteneur): void {
            $version = config()->string('app.version');
            $revision = config()->string('app.revision');
            $maintenant = now()->toIso8601String();
            $precedente = self::lue($conteneur);
            $memeImage = $precedente !== null && $precedente['version'] === $version && $precedente['revision'] === $revision;

            Cache::forever(self::cle($conteneur), [
                'version' => $version,
                'revision' => $revision,
                'depuis' => $memeImage ? $precedente['depuis'] : $maintenant,
                'le' => $maintenant,
            ]);
        }, report: false);
    }

    /**
     * L'annonce du conteneur, ou null s'il ne s'est jamais annoncé.
     *
     * @return array{version: string, revision: string, depuis: string, le: string}|null
     */
    public static function lue(string $conteneur): ?array
    {
        $annonce = Cache::get(self::cle($conteneur));

        if (! is_array($annonce)) {
            return null;
        }

        foreach (['version', 'revision', 'depuis', 'le'] as $champ) {
            if (! is_string($annonce[$champ] ?? null)) {
                return null;
            }
        }

        /** @var array{version: string, revision: string, depuis: string, le: string} $annonce */
        return [
            'version' => $annonce['version'],
            'revision' => $annonce['revision'],
            'depuis' => $annonce['depuis'],
            'le' => $annonce['le'],
        ];
    }

    private static function cle(string $conteneur): string
    {
        return 'sante:version:'.$conteneur;
    }
}
