<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Un argument de ligne de commande se lit partout : `docker inspect` (la
 * commande et la sonde d'un service), `docker ps --no-trunc`, `ps` sur l'hôte.
 * La composition de production passait ainsi le mot de passe de MySQL à la
 * sonde de `db` (`mysqladmin ping -p${DB_PASSWORD}`) et celui de Redis aux
 * deux instances (`redis-server --requirepass ${REDIS_PASSWORD}`) (#2353).
 * Un secret entre désormais par l'environnement du conteneur (`MYSQL_PWD` pour
 * mysqladmin, une configuration lue sur l'entrée standard pour Redis), que ni
 * `ps` ni `docker ps` ne montrent.
 *
 * La garde lit la `command`, l'`entrypoint` et la sonde (`healthcheck.test`)
 * de chaque service : aucune variable de la pile qui porte un secret n'y entre,
 * ni par l'interpolation de Compose (`${NOM}`, qui écrit la valeur dans la
 * configuration du conteneur), ni par une référence du shell (`$$NOM`) hors des
 * deux seuls gestes qui ne la laissent pas dans une ligne de commande : une
 * affectation (`MYSQL_PWD="$$NOM"`) et `printf %s "$$NOM"`, builtin de
 * l'interpréteur. Elle refuse aussi les options qui prennent un mot de passe.
 */

/**
 * Ce que le nom d'une variable dit d'un secret.
 */
const SECRETS_EN_ARGUMENT_MOTIF = '/(?:PASSWORD|PASSWD|SECRET|TOKEN|PRIVATE_KEY|PWD)$|^APP_KEY$/i';

/**
 * Le texte de ce qu'un service lance : sa commande, son point d'entrée et sa
 * sonde, une liste se lisant mot à mot, un texte tel quel.
 *
 * @param  array<mixed>  $service
 * @return array<string, string>
 */
function secretsEnArgumentLignesDuService(array $service): array
{
    $lignes = [];

    foreach (['command' => 'command', 'entrypoint' => 'entrypoint', 'healthcheck.test' => 'healthcheck.test'] as $nom => $chemin) {
        $valeur = data_get($service, $chemin);

        if (is_array($valeur)) {
            $valeur = implode("\n", array_filter($valeur, is_string(...)));
        }

        if (is_string($valeur) && $valeur !== '') {
            $lignes[$nom] = $valeur;
        }
    }

    return $lignes;
}

/**
 * Les noms de variables qui portent un secret dans une composition : ceux
 * dont le nom le dit (`DB_PASSWORD`), et ceux dont la valeur interpole l'un
 * d'eux (`REDISCLI_AUTH: ${REDIS_PASSWORD}`).
 *
 * @param  array<mixed>  $composition
 * @return list<string>
 */
function secretsEnArgumentNoms(array $composition): array
{
    $motif = SECRETS_EN_ARGUMENT_MOTIF;
    $environnements = [$composition['x-app-env'] ?? []];

    foreach ((array) ($composition['services'] ?? []) as $service) {
        $environnements[] = is_array($service) ? ($service['environment'] ?? []) : [];
    }

    $noms = [];

    foreach ($environnements as $environnement) {
        foreach (is_array($environnement) ? $environnement : [] as $nom => $valeur) {
            $trouvees = [];

            if (is_string($valeur)) {
                preg_match_all('/\$\{([A-Za-z_][A-Za-z0-9_]*)/', $valeur, $trouvees);
            }

            $interpole = array_filter($trouvees[1] ?? [], static fn (string $variable): bool => preg_match($motif, $variable) === 1) !== [];

            if (preg_match($motif, (string) $nom) === 1 || $interpole) {
                $noms[] = (string) $nom;
            }
        }
    }

    return array_values(array_unique($noms));
}

/**
 * Ce qu'une ligne lancée laisse voir d'un secret, une phrase par défaut.
 *
 * @param  list<string>  $secrets  les noms de variables qui portent un secret
 * @return list<string>
 */
function secretsEnArgumentDefauts(string $texte, array $secrets): array
{
    $defauts = [];

    // Les deux gestes qui ne laissent pas la valeur dans une ligne de commande.
    $reste = (string) preg_replace(
        ['/\bMYSQL_PWD="\$\$\{?[A-Z0-9_]+\}?"/', '/\bprintf\s+%s\s+"\$\$\{?[A-Z0-9_]+\}?"/'],
        '',
        $texte,
    );

    preg_match_all('/\$\$?\{?([A-Za-z_][A-Za-z0-9_]*)/', $reste, $references);

    foreach (array_unique($references[1]) as $nom) {
        if (in_array($nom, $secrets, true) || preg_match(SECRETS_EN_ARGUMENT_MOTIF, $nom) === 1) {
            $defauts[] = "référence à {$nom}";
        }
    }

    if (preg_match('/(?:^|\s)(?:--(?:requirepass|masterauth|password|pass|auth)\b|-a\s|-p\S)/i', $reste) === 1) {
        $defauts[] = 'option qui prend un mot de passe';
    }

    return $defauts;
}

/**
 * Chaque défaut de chaque service d'une composition : « service.ligne : défaut ».
 *
 * @param  array<mixed>  $composition
 * @return list<string>
 */
function secretsEnArgumentDefautsDeLaComposition(array $composition): array
{
    $defauts = [];
    $services = $composition['services'] ?? [];
    $secrets = secretsEnArgumentNoms($composition);

    foreach (is_array($services) ? $services : [] as $nom => $service) {
        foreach (secretsEnArgumentLignesDuService(is_array($service) ? $service : []) as $ligne => $texte) {
            foreach (secretsEnArgumentDefauts($texte, $secrets) as $defaut) {
                $defauts[] = "{$nom}.{$ligne} : {$defaut}";
            }
        }
    }

    return $defauts;
}

it('ne met aucun secret dans la commande, le point d’entrée ou la sonde d’un service', function (): void {
    $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));

    expect($composition)->toBeArray();
    assert(is_array($composition));
    expect(secretsEnArgumentDefautsDeLaComposition($composition))->toBe([]);
});

it('lit ce que la garde doit lire : redis et la sonde de db portent bien le geste qui la satisfait', function (): void {
    $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));
    assert(is_array($composition));

    $redis = secretsEnArgumentLignesDuService((array) data_get($composition, 'services.redis'));
    $sonde = secretsEnArgumentLignesDuService((array) data_get($composition, 'services.db'));

    expect($redis['command'])->toContain('printf %s "$$REDIS_PASSWORD"')
        ->and($sonde['healthcheck.test'])->toContain('MYSQL_PWD="$$MYSQL_ROOT_PASSWORD"');
});

it('refuse ce que la composition faisait avant', function (string $service, array $definition): void {
    expect(secretsEnArgumentDefautsDeLaComposition(['services' => [$service => $definition]]))->not->toBe([]);
})->with([
    'la sonde de db' => ['db', ['healthcheck' => ['test' => ['CMD', 'mysqladmin', 'ping', '-p${DB_PASSWORD}']]]],
    'la commande de redis' => ['redis', ['command' => 'redis-server --requirepass ${REDIS_PASSWORD:?mot de passe de Redis} --appendonly yes']],
    'la commande de redis en liste' => ['redis', ['command' => ['redis-server', '--requirepass', '${REDIS_PASSWORD}']]],
    'une référence du shell en argument' => ['redis', ['command' => ['sh', '-c', 'exec redis-server --port 6379 --requirepass "$$REDIS_PASSWORD"']]],
    'un jeton en argument' => ['app', ['command' => 'curl -H "Authorization: $$API_TOKEN" http://x']],
    'la clé de l’application en argument' => ['worker', ['command' => 'php artisan horizon --key=${APP_KEY}']],
    'un mot de passe tapé après -a' => ['redis', ['healthcheck' => ['test' => ['CMD-SHELL', 'redis-cli -a "$$REDIS_PASSWORD" ping']]]],
    'une variable dont la valeur est un secret, sans le dire dans son nom' => ['redis', ['environment' => ['REDISCLI_AUTH' => '${REDIS_PASSWORD}'], 'command' => ['sh', '-c', 'notify $$REDISCLI_AUTH']]],
    'un point d’entrée' => ['db', ['entrypoint' => 'run-with --secret ${SESSION_SECRET}']],
]);

it('admet les gestes qui ne laissent pas la valeur dans une ligne de commande', function (string $service, array $definition): void {
    expect(secretsEnArgumentDefautsDeLaComposition(['services' => [$service => $definition]]))->toBe([]);
})->with([
    'MYSQL_PWD en affectation' => ['db', ['healthcheck' => ['test' => ['CMD-SHELL', 'MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" mysqladmin ping -uroot']]]],
    'printf, builtin de l’interpréteur' => ['redis', ['command' => ['sh', '-c', 'x=$$(printf %s "$$REDIS_PASSWORD" | sed s/a/b/)']]],
    'une variable qui n’est pas un secret' => ['app', ['command' => 'php artisan serve --host=$$HOTE --port=${PORT}']],
    'une sonde sans secret' => ['redis', ['healthcheck' => ['test' => ['CMD-SHELL', '[ "$$(redis-cli ping)" = PONG ]']]]],
]);
