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
 * seuls gestes qui ne la laissent pas dans une ligne de commande (voir
 * `secretsEnArgumentDefauts`). Elle lit aussi ce que `docker inspect` et
 * l'historique de l'image montrent hors de toute commande : l'image, les
 * étiquettes et les arguments de construction. Elle refuse enfin les options
 * qui prennent un mot de passe, `-p <valeur>` de la famille MySQL comprise.
 * Les noms de secrets se lisent en anglais et en français, et l'environnement
 * d'un service s'écrit en table ou en liste (`- NOM=valeur`) (#2397).
 */

/**
 * Ce que le nom d'une variable dit d'un secret : un mot entier, en anglais ou
 * en français (`DB_PASSWORD`, `REDISCLI_AUTH`, `mot_de_passe`, `cle_api`,
 * `JETON`), où qu'il soit dans le nom. Un mot entier : `BYPASS` n'en est pas un.
 */
const SECRETS_EN_ARGUMENT_MOTIF = '/(?<![A-Za-z])(?:PASSWORD|PASSWD|PASS|PASSE|PWD|MDP|SECRET|TOKEN|JETON|AUTH|CREDENTIALS?|PRIVATE_KEY|KEY|CLE|CLEF|CLES|CLEFS)(?![A-Za-z])/i';

/**
 * Les binaires de la famille MySQL, dont `-p <valeur>` est le mot de passe.
 */
const SECRETS_EN_ARGUMENT_BINAIRES_MYSQL = '(?:mysql|mysqladmin|mysqldump|mysqlcheck|mysqlpump|mariadb|mariadb-admin|mariadb-dump)';

/**
 * Des couples NOM => valeur, qu'une section de composition s'écrive en table
 * (`NOM: valeur`) ou en liste (`- NOM=valeur`, `- NOM` pour une variable
 * reprise de l'hôte).
 *
 * @return array<string, string>
 */
function secretsEnArgumentCouples(mixed $section): array
{
    $couples = [];

    foreach (is_array($section) ? $section : [] as $cle => $valeur) {
        if (is_int($cle)) {
            [$nom, $texte] = array_pad(explode('=', is_string($valeur) ? $valeur : '', 2), 2, '');
            $couples[$nom] = $texte;

            continue;
        }

        $couples[$cle] = is_scalar($valeur) ? (string) $valeur : '';
    }

    return $couples;
}

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
 * Ce qu'un service écrit hors de toute ligne de commande mais que `docker
 * inspect` ou l'historique de l'image montrent : l'image, les étiquettes (du
 * service et de la construction) et les arguments de construction. Aucun
 * interpréteur n'y passe : pas de geste exempté, toute interpolation d'un
 * secret est un défaut.
 *
 * @param  array<mixed>  $service
 * @return array<string, string>
 */
function secretsEnArgumentChampsInertes(array $service): array
{
    $champs = [];
    $construction = $service['build'] ?? [];

    if (is_string($service['image'] ?? null)) {
        $champs['image'] = $service['image'];
    }

    if (is_string($construction)) {
        $champs['build'] = $construction;
    }

    $sections = [
        'labels' => $service['labels'] ?? [],
        'build.labels' => is_array($construction) ? ($construction['labels'] ?? []) : [],
        'build.args' => is_array($construction) ? ($construction['args'] ?? []) : [],
    ];

    foreach ($sections as $nom => $section) {
        foreach (secretsEnArgumentCouples($section) as $cle => $valeur) {
            $champs["{$nom}.{$cle}"] = $valeur;
        }
    }

    return $champs;
}

/**
 * Les noms de variables qui portent un secret dans une composition : ceux
 * dont le nom le dit (`DB_PASSWORD`), et ceux dont la valeur interpole l'un
 * d'eux (`REDISCLI_AUTH: ${REDIS_PASSWORD}`), l'environnement s'écrivant en
 * table ou en liste.
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
        foreach (secretsEnArgumentCouples($environnement) as $nom => $valeur) {
            preg_match_all('/(?<!\$)\$\{?([A-Za-z_][A-Za-z0-9_]*)/', $valeur, $trouvees);

            $interpole = array_filter($trouvees[1], static fn (string $variable): bool => preg_match($motif, $variable) === 1) !== [];

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
 * Ne laissent pas la valeur dans une ligne de commande, et sont donc admis :
 * `MYSQL_PWD="$$NOM"` en affectation, au début d'une commande ; `printf %s
 * "$$NOM"` au début d'une commande et devant un tube (sa sortie est l'entrée
 * d'un autre processus) ; `NOM=$$(… printf %s "$$NOM" …)`, la substitution
 * affectée à une variable. Le même `printf` dans l'argument d'une commande
 * (`curl "$$(printf %s "$$NOM")"`) est un argument comme un autre. Le corps
 * d'un document intégré (`<<FIN`) est l'entrée d'un processus, pas un argument.
 *
 * @param  list<string>  $secrets  les noms de variables qui portent un secret
 * @param  bool  $interprete  vrai pour une ligne qu'un interpréteur lit (`$$NOM`)
 * @return list<string>
 */
function secretsEnArgumentDefauts(string $texte, array $secrets, bool $interprete = true): array
{
    $defauts = [];
    $reste = $texte;

    if ($interprete) {
        $debut = '(?:^|[;&|(\n]|\benv\s|\bexport\s)';
        $reference = '"\$\$\{?[A-Za-z0-9_]+\}?"';

        $reste = (string) preg_replace(
            [
                '/<<-?\s*([\'"]?)(\w+)\1([^\n]*\n)(.*?)\n\s*\2\b/s',
                "/({$debut}\\s*)MYSQL_PWD={$reference}/",
                "/({$debut}\\s*[A-Za-z_][A-Za-z0-9_]*=)\\\$\\\$\\([^()]*\\bprintf\\s+%s\\s+{$reference}[^()]*\\)/",
                "/((?:^|[;&\\n])\\s*)printf\\s+%s\\s+{$reference}\\s*\\|(?!\\|)/",
            ],
            ['$3', '$1', '$1', '$1|'],
            $reste,
        );
    }

    preg_match_all('/(?<!\$)\$\$?\{?([A-Za-z_][A-Za-z0-9_]*)/', $reste, $references);

    foreach (array_unique($references[1]) as $nom) {
        if (in_array($nom, $secrets, true) || preg_match(SECRETS_EN_ARGUMENT_MOTIF, $nom) === 1) {
            $defauts[] = "référence à {$nom}";
        }
    }

    $mysql = SECRETS_EN_ARGUMENT_BINAIRES_MYSQL;
    $options = '(?:^|\s)(?:--(?:requirepass|masterauth|password|passwd|pass|secret|token|jeton|cle|key|api-key|auth|mot-de-passe)(?=[=\s]|$)|-a\s|-p\S|-p\s+["\']?\$)';

    if (preg_match("/{$options}/i", $reste) === 1 || preg_match("/\\b{$mysql}\\b[^|;&\\n]*\\s-p\\s+\\S/", $reste) === 1) {
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
        $service = is_array($service) ? $service : [];

        foreach (secretsEnArgumentLignesDuService($service) as $ligne => $texte) {
            foreach (secretsEnArgumentDefauts($texte, $secrets) as $defaut) {
                $defauts[] = "{$nom}.{$ligne} : {$defaut}";
            }
        }

        foreach (secretsEnArgumentChampsInertes($service) as $champ => $texte) {
            foreach (secretsEnArgumentDefauts($texte, $secrets, false) as $defaut) {
                $defauts[] = "{$nom}.{$champ} : {$defaut}";
            }
        }

        $construction = $service['build'] ?? null;

        foreach (secretsEnArgumentCouples(is_array($construction) ? ($construction['args'] ?? []) : []) as $cle => $valeur) {
            if ($valeur !== '' && preg_match(SECRETS_EN_ARGUMENT_MOTIF, $cle) === 1) {
                $defauts[] = "{$nom}.build.args.{$cle} : un secret dans l'historique de l'image";
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

/**
 * La vraie composition de production, mutée : chaque mutation pose une valeur
 * à un chemin (`['services.db.image', 'x']`), ou, avec un troisième élément
 * vrai, l'ajoute à la liste qui s'y trouve (`['services.redis.command', 'x', true]`).
 *
 * @param  array<mixed>  $mutations
 * @return array<mixed>
 */
function secretsEnArgumentCompositionMutee(array $mutations): array
{
    $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));
    assert(is_array($composition));

    foreach ($mutations as $mutation) {
        $mutation = (array) $mutation;
        $chemin = is_string($mutation[0] ?? null) ? $mutation[0] : '';
        $valeur = ($mutation[2] ?? false) === true ? [...(array) data_get($composition, $chemin), $mutation[1]] : $mutation[1];

        data_set($composition, $chemin, $valeur);
    }

    assert(is_array($composition));

    return $composition;
}

it('refuse, sur la vraie composition, chaque trou que la garde laissait', function (array $mutations): void {
    expect(secretsEnArgumentDefautsDeLaComposition(secretsEnArgumentCompositionMutee($mutations)))->not->toBe([]);
})->with([
    'printf dans l’argument d’une commande, que l’exemption admettait sur toute la ligne' => [[['services.redis.command', 'curl -H "X: $$(printf %s "$$REDIS_PASSWORD")" http://x', true]]],
    'printf sans tube ni affectation' => [[['services.redis.command', 'notifier "$$(printf %s "$$REDIS_PASSWORD")"', true]]],
    'MYSQL_PWD donné en argument et non en affectation' => [[['services.db.healthcheck.test', ['CMD-SHELL', 'mysqladmin ping MYSQL_PWD="$$MYSQL_ROOT_PASSWORD"']]]],
    'un nom français : mot_de_passe' => [[['services.redis.command', 'notifier "$$mot_de_passe"', true]]],
    'un nom français : cle_api' => [[['services.redis.command', 'notifier "$$cle_api"', true]]],
    'un nom français : JETON' => [[['services.redis.command', 'notifier "$$JETON_DEPLOIEMENT"', true]]],
    'un nom en AUTH' => [[['services.redis.command', 'notifier "$$MON_AUTH"', true]]],
    'un nom en PASS' => [[['services.redis.command', 'notifier "$$DB_PASS"', true]]],
    'une variable d’environnement en liste, au nom neutre, qui reprend un secret' => [[
        ['services.redis.environment', ['MA_VALEUR=${REDIS_PASSWORD}']],
        ['services.redis.command', 'notifier "$$MA_VALEUR"', true],
    ]],
    'une variable d’environnement en liste, que sa valeur seule trahit' => [[
        ['services.worker.environment', ['COURRIEL_AUTH=${MAIL_PASSWORD}']],
        ['services.worker.command', 'notifier "$$COURRIEL_AUTH"'],
    ]],
    'une étiquette en table' => [[['services.app.labels', ['com.exemple.cle' => '${APP_KEY}']]]],
    'une étiquette en liste' => [[['services.app.labels', ['com.exemple.cle=${DB_PASSWORD}']]]],
    'une étiquette de construction' => [[['services.app.build', ['context' => '.', 'labels' => ['jeton' => '${REDIS_PASSWORD}']]]]],
    'l’image' => [[['services.db.image', 'registre.exemple/mysql:${REDIS_PASSWORD}']]],
    'un argument de construction qui interpole un secret' => [[['services.app.build', ['context' => '.', 'args' => ['NPM' => '${DB_PASSWORD}']]]]],
    'un argument de construction en liste' => [[['services.app.build', ['context' => '.', 'args' => ['GIT=${APP_KEY}']]]]],
    'un argument de construction au nom d’un secret, écrit en dur' => [[['services.app.build', ['context' => '.', 'args' => ['NPM_TOKEN' => 'abc123']]]]],
    '-p suivi d’une espace et d’une valeur, pour mysqladmin' => [[['services.db.healthcheck.test', ['CMD-SHELL', 'mysqladmin -uroot -p motdepasse ping']]]],
    '-p suivi d’une espace et d’une référence' => [[['services.db.healthcheck.test', ['CMD-SHELL', 'mysqladmin -uroot -p "$$NEUTRE" ping']]]],
    'une option --token' => [[['services.redis.command', 'notifier --token abc', true]]],
]);

it('admet, sur la vraie composition, ce qui n’est pas un secret en argument', function (array $mutations): void {
    expect(secretsEnArgumentDefautsDeLaComposition(secretsEnArgumentCompositionMutee($mutations)))->toBe([]);
})->with([
    'printf devant un tube, en début de commande' => [[['services.redis.command', 'printf %s "$$REDIS_PASSWORD" | wc -c', true]]],
    'printf dans une affectation' => [[['services.redis.command', 'longueur=$$(printf %s "$$REDIS_PASSWORD" | wc -c)', true]]],
    'MYSQL_PWD en affectation derrière env' => [[['services.db.healthcheck.test', ['CMD-SHELL', 'env MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" mysqladmin ping']]]],
    'un secret dans le corps d’un document intégré' => [[['services.redis.command', "cat <<FIN\nrequirepass \"\$\$mot_de_passe\"\nFIN", true]]],
    'mkdir -p, qui n’est pas un mot de passe' => [[['services.redis.command', 'mkdir -p /data/dossier', true]]],
    'une étiquette et un argument de construction sans secret' => [[
        ['services.app.labels', ['com.exemple.version=${VERSION:-1}']],
        ['services.app.build', ['context' => '.', 'args' => ['NODE_VERSION' => '24']]],
    ]],
]);
