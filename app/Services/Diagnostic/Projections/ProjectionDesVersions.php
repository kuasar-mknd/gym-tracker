<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use App\Services\Diagnostic\ValeurLue;
use App\Services\Diagnostic\Vocabulaire;
use App\Support\Sante\AnnonceDeVersion;

/**
 * Ce que chaque conteneur annonce de l'image qu'il exécute, vu par le
 * diagnostic (#2032) : version, révision, depuis quand, dernière annonce. Un
 * conteneur qui ne s'est pas annoncé a toutes ses valeurs nulles.
 */
final class ProjectionDesVersions
{
    public const string VERSION = '/^[A-Za-z0-9][A-Za-z0-9.+_-]{0,63}$/D';

    /**
     * @return list<array{conteneur: string, version: string|null, revision: string|null, depuis: string|null, annonce_le: string|null}>
     */
    public function liste(): array
    {
        return array_map(static function (string $conteneur): array {
            $annonce = AnnonceDeVersion::lue($conteneur);

            return [
                'conteneur' => $conteneur,
                'version' => Vocabulaire::forme(self::VERSION, $annonce['version'] ?? null),
                'revision' => Vocabulaire::forme(self::VERSION, $annonce['revision'] ?? null),
                'depuis' => ValeurLue::instant($annonce['depuis'] ?? null),
                'annonce_le' => ValeurLue::instant($annonce['le'] ?? null),
            ];
        }, AnnonceDeVersion::CONTENEURS);
    }

    /**
     * La version, la révision et l'environnement de l'application qui répond.
     *
     * @return array{version: string|null, revision: string|null, environnement: string|null}
     */
    public function application(): array
    {
        return [
            'version' => Vocabulaire::forme(self::VERSION, config('app.version')),
            'revision' => Vocabulaire::forme(self::VERSION, config('app.revision')),
            'environnement' => Vocabulaire::forme(Vocabulaire::IDENTIFIANT, config('app.env')),
        ];
    }
}
