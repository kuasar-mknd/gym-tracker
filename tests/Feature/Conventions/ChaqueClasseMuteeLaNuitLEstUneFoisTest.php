<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * La passe de mutation nocturne découpe ses espaces de noms en parts, chacune
 * avec son délai et son seuil (.github/workflows/mutation.yml). Une part se
 * borne par `scope` (`--class`, un espace de noms ou une classe) et, quand elle
 * en laisse une portion à une autre part, par `ignore` (`--ignore`, un chemin).
 *
 * Découper laisse deux façons de se tromper sans que rien ne rougisse : une
 * classe qu'aucune part ne mute, que la nuit déclare verte sans l'avoir
 * regardée, ou une classe que deux parts mutent, payée deux fois sur deux
 * délais. Cette garde exige que chaque classe des espaces mutés appartienne à
 * une part, et à une seule, et que chaque part mute au moins une classe.
 */

/**
 * Les parts de la matrice, telles que le workflow les déclare.
 *
 * @return list<array{scope: string, ignore: list<string>}>
 */
function partsDeLaNuit(): array
{
    $workflow = Yaml::parseFile(base_path('.github/workflows/mutation.yml'));
    $parts = data_get($workflow, 'jobs.mutation.strategy.matrix.include');

    if (! is_array($parts) || $parts === []) {
        throw new RuntimeException('mutation.yml ne déclare plus ses parts sous jobs.mutation.strategy.matrix.include : la garde ne sait plus quoi lire.');
    }

    return array_values(array_map(static function (mixed $part): array {
        $scope = is_array($part) ? ($part['scope'] ?? null) : null;
        $ignore = is_array($part) ? ($part['ignore'] ?? '') : '';

        if (! is_string($scope) || $scope === '' || ! is_string($ignore)) {
            throw new RuntimeException('Une part de mutation.yml n’a pas de scope lisible.');
        }

        return ['scope' => $scope, 'ignore' => $ignore === '' ? [] : explode(',', $ignore)];
    }, $parts));
}

/**
 * La classe que déclare un fichier de app/, par la convention PSR-4 du dépôt.
 */
function classeDuFichierMute(string $chemin): string
{
    return 'App\\'.str_replace('/', '\\', mb_substr($chemin, mb_strlen('app/'), -mb_strlen('.php')));
}

/**
 * Une part mute-t-elle ce fichier ?
 *
 * @param  array{scope: string, ignore: list<string>}  $part
 */
function laPartMute(array $part, string $chemin): bool
{
    $classe = classeDuFichierMute($chemin);

    if ($classe !== $part['scope'] && ! str_starts_with($classe, $part['scope'].'\\')) {
        return false;
    }

    foreach ($part['ignore'] as $ignore) {
        if (str_starts_with($chemin, mb_rtrim($ignore, '/').'/') || $chemin === $ignore) {
            return false;
        }
    }

    return true;
}

/**
 * Les fichiers des espaces de noms que la nuit mute (App\Actions,
 * App\Services…), chemins relatifs à la racine du dépôt.
 *
 * @param  list<array{scope: string, ignore: list<string>}>  $parts
 * @return list<string>
 */
function fichiersDesEspacesMutes(array $parts): array
{
    $racines = array_unique(array_map(
        static fn (array $part): string => implode('/', array_slice(explode('\\', $part['scope']), 1, 1)),
        $parts,
    ));
    $fichiers = [];

    foreach ($racines as $racine) {
        $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/'.$racine), FilesystemIterator::SKIP_DOTS));

        foreach ($iterateur as $fichier) {
            if ($fichier instanceof SplFileInfo && $fichier->getExtension() === 'php') {
                $fichiers[] = mb_substr($fichier->getPathname(), mb_strlen(base_path()) + 1);
            }
        }
    }

    sort($fichiers);

    return $fichiers;
}

it('fait muter chaque classe des espaces mutés par une part, et une seule', function (): void {
    $parts = partsDeLaNuit();
    $ecarts = [];

    foreach (fichiersDesEspacesMutes($parts) as $chemin) {
        $mutantes = array_filter($parts, static fn (array $part): bool => laPartMute($part, $chemin));

        if (count($mutantes) !== 1) {
            $ecarts[] = sprintf('%s : %d part(s)', $chemin, count($mutantes));
        }
    }

    expect($ecarts)->toBe([]);
});

it('ne déclare aucune part qui ne mute rien', function (): void {
    $parts = partsDeLaNuit();
    $fichiers = fichiersDesEspacesMutes($parts);

    foreach ($parts as $part) {
        $mutes = array_filter($fichiers, static fn (string $chemin): bool => laPartMute($part, $chemin));

        expect($mutes)->not->toBeEmpty("La part {$part['scope']} ne mute aucune classe.");
    }
});
