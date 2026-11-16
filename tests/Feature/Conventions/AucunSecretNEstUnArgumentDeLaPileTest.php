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
 * en français (`DB_PASSWORD`, `mot_de_passe`, `cle_api`, `JETON`), où qu'il
 * soit dans le nom (`BYPASS` n'en est pas un) ; ou un nom qui FINIT par un mot
 * qui en désigne un, collé ou non (`REDISCLI_AUTH`, `APP_KEY`, `GITHUBTOKEN`).
 * `AUTH` et `KEY` ne comptent qu'en fin de nom : `MAIL_AUTH_MODE` et
 * `KEY_LAYOUT` n'en sont pas.
 */
const SECRETS_EN_ARGUMENT_MOTIF = '/(?<![A-Za-z])(?:PASSWORD|PASSWD|PASSPHRASE|PASS|PASSE|PWD|PW|MDP|SECRETS?|TOKEN|JETON|CREDENTIALS?|PRIVATE_KEY|CLE|CLEF|CLES|CLEFS)(?![A-Za-z])|(?:PASSWORD|PASSWD|PASSPHRASE|PWD|SECRETS?|TOKEN|JETON|APIKEY|MOTDEPASSE|AUTH|KEY)$/i';

/**
 * Ce que lit sans le lancer un processus au bout d'un tube : le secret qu'il
 * reçoit sur son entrée ne finit pas dans sa ligne de commande. Tout autre
 * lecteur (`xargs`, `sh`, `curl`…) peut le remettre en argument.
 */
const SECRETS_EN_ARGUMENT_LECTEURS_SURS = '(?:sed|tr|base64|wc|cat|head|tail|cut|openssl|sha256sum|sha1sum|md5sum|tee|redis-cli|mysql)';

/**
 * Les seuls destinataires dont un document intégré (`<<FIN`) n'est qu'une
 * entrée : Redis (son entrée standard est une configuration), `redis-cli`,
 * `mysql` (du SQL) et `cat > fichier`, sans tube. Tout autre destinataire
 * (`sh`, `python3`, `php`, `perl`, `node`, `cat | sh`…) peut exécuter le corps :
 * il se lit alors comme du shell.
 */
const SECRETS_EN_ARGUMENT_DESTINATAIRES_SURS = '/^\s*(?:(?:exec\s+)?(?:docker-entrypoint\.sh\s+)?(?:redis-server|redis-cli|mysql)(?![\w.-])|cat\s*>\s*\S+\s*)/';

/**
 * Les binaires de la famille MySQL. `-p <valeur>` n'y est pas un mot de passe
 * (le client le demande ensuite) : la garde le refuse par prudence, parce
 * qu'on l'écrit souvent ainsi à tort.
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

    $chemins = ['command' => 'command', 'entrypoint' => 'entrypoint', 'healthcheck.test' => 'healthcheck.test'];

    foreach (['post_start', 'pre_stop'] as $crochet) {
        foreach (array_keys(is_array($service[$crochet] ?? null) ? $service[$crochet] : []) as $position) {
            $chemins["{$crochet}.{$position}.command"] = "{$crochet}.{$position}.command";
        }
    }

    foreach ($chemins as $nom => $chemin) {
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
        'logging.options' => data_get($service, 'logging.options', []),
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
 * - `MYSQL_PWD="$$NOM"` en affectation, au début d'une commande ;
 * - `printf %s "$$NOM"` au début d'une commande, devant un tube dont l'autre
 *   bout est un lecteur sûr (`sed`, `tr`, `base64`, `wc`… : jamais `xargs`,
 *   `sh` ou `curl`, qui peuvent remettre le secret en argument) ;
 * - `NOM=$$(printf %s "$$NOM" | lecteur sûr)`, la substitution affectée à une
 *   variable ; le nom affecté devient lui-même un secret (`valeur=$$(…)` puis
 *   `curl -u "u:$$valeur"` est refusé) ;
 *   Tous les maillons d'un tube doivent être des lecteurs sûrs : un seul
 *   `xargs`, `sh` ou `curl` après un `sed` suffit à le refuser ;
 * - le corps d'un document intégré (`<<FIN`) donné à l'un des seuls
 *   destinataires sûrs (`redis-server`, `redis-cli`, `mysql`, `cat > fichier`),
 *   sur une ligne sans `|`, `;` ni `&&` après le marqueur, et, s'il n'est pas
 *   coté (`<<'FIN'`), sans `$(` ni apostrophe inverse que le shell exécuterait.
 *   Tout autre destinataire (`sh`, `cat | sh`, `python3`, `php`, `perl`,
 *   `node`…) peut exécuter le corps : il se lit comme du shell ;
 *
 * Ce qu'elle ne peut pas voir : un secret qui voyage dans une variable
 * intermédiaire à plusieurs pas, un lecteur sûr qui le remet en argument par
 * lui-même (`openssl … -pass pass:`), les alias d'environnement à plus d'un
 * niveau, un secret construit par morceaux (`$$A$$B`), et ce qu'un script
 * externe copié dans l'image fait de son environnement. `docker inspect`
 * montre de toute façon l'environnement du conteneur.
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
        $substitutions = [
            '__D__' => '(?:^|[;&|(\n])\s*(?:(?:env|export)\s+)?',
            '__R__' => '"\$\$\{?[A-Za-z0-9_]+\}?"',
            '__S__' => SECRETS_EN_ARGUMENT_LECTEURS_SURS,
        ];

        $reste = (string) preg_replace_callback(
            '/((?:[^;&|\n\\\\]|\\\\.)*)<<-?\s*([\'"]?)(\w+)\2([^\n]*\n)(.*?)\n\s*\3\b/s',
            static fn (array $trouve): string => preg_match(SECRETS_EN_ARGUMENT_DESTINATAIRES_SURS, $trouve[1]) === 1
                && preg_match('/[|;]|&&/', $trouve[4]) === 0
                && ($trouve[2] !== '' || preg_match('/\$\$\(|`/', $trouve[5]) === 0)
                    ? $trouve[1].$trouve[4]
                    : $trouve[0],
            $reste,
        );

        preg_match_all(strtr('/__D__([A-Za-z_][A-Za-z0-9_]*)=\$\$\(\s*printf\s+%s\s+__R__/', $substitutions), $reste, $affectes);
        $secrets = [...$secrets, ...$affectes[1]];

        $reste = (string) preg_replace(
            array_map(static fn (string $motif): string => strtr($motif, $substitutions), [
                '/(__D__)MYSQL_PWD=__R__/',
                '/(__D__[A-Za-z_][A-Za-z0-9_]*=)\$\$\(\s*printf\s+%s\s+__R__(?:\s*\|\s*__S__\b(?:(?!&&)[^;()|])*)*\s*\)/',
                '/((?:^|[;&\n])\s*)printf\s+%s\s+__R__(?:\s*\|\s*__S__\b(?>(?:(?!&&)[^|;\n])*))+(?!\s*\|)/',
            ]),
            ['$1', '$1', '$1'],
            $reste,
        );
    }

    preg_match_all('/(?<!\$)\$\$?\{?([A-Za-z_][A-Za-z0-9_]*)|\bprintenv\s+([A-Za-z_][A-Za-z0-9_]*)/', $reste, $references);

    foreach (array_unique(array_filter([...$references[1], ...$references[2]], static fn (string $nom): bool => $nom !== '')) as $nom) {
        if (in_array($nom, $secrets, true) || preg_match(SECRETS_EN_ARGUMENT_MOTIF, $nom) === 1) {
            $defauts[] = "référence à {$nom}";
        }
    }

    $mysql = SECRETS_EN_ARGUMENT_BINAIRES_MYSQL;
    $longues = '(?:^|\s)--(?:requirepass|masterauth|password|passwd|passphrase|pass|secret|token|jeton|cle|key|api-key|auth|mot-de-passe)(?=[=\s]|$)';
    $courtes = '(?:^|\s)(?:-a\s|-p\S)';
    // `-p <valeur>` de la famille MySQL, refusé par prudence, d'une ligne de
    // shell comme d'une liste (dont les éléments se joignent par des retours
    // à la ligne).
    $apres = "/\\b{$mysql}\\b[^|;&]*?\\s-p\\s+\\S/";

    if (preg_match("/{$longues}/i", $reste) === 1 || preg_match("/{$courtes}/", $reste) === 1 || preg_match($apres, $reste) === 1) {
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

        $nommes = [
            'build.args' => is_array($construction) ? ($construction['args'] ?? []) : [],
            'logging.options' => data_get($service, 'logging.options', []),
        ];

        foreach ($nommes as $section => $couples) {
            foreach (secretsEnArgumentCouples($couples) as $cle => $valeur) {
                // Un chemin ou une adresse n'est pas un secret (`syslog-tls-key: /etc/ssl/k.pem`) ;
                // un argument de construction sans valeur la prend de l'hôte.
                $estUnChemin = preg_match('~^(?:\.{0,2}/|[a-z][a-z0-9+.-]*://)~i', $valeur) === 1;
                $sansValeur = $valeur === '' && $section === 'build.args';

                if (preg_match(SECRETS_EN_ARGUMENT_MOTIF, $cle) === 1 && ! $estUnChemin && ($valeur !== '' || $sansValeur)) {
                    $defauts[] = "{$nom}.{$section}.{$cle} : un secret que `docker inspect` ou l'historique de l'image montrent";
                }
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
    'un argument de construction repris de l’hôte, sans valeur (liste)' => [[['services.app.build', ['context' => '.', 'args' => ['NPM_TOKEN']]]]],
    'un argument de construction repris de l’hôte, sans valeur (table)' => [[['services.app.build', ['context' => '.', 'args' => ['NPM_TOKEN' => null]]]]],
    '-p suivi d’une valeur, en liste (forme exec)' => [[['services.db.healthcheck.test', ['CMD', 'mysqladmin', '-uroot', '-p', 'motdepasse', 'ping']]]],
    'un secret passé par --env, qui n’est pas l’affectation d’une commande' => [[['services.redis.command', 'outil --env JETON=$$(printf %s "$$APP_KEY")', true]]],
    'un document intégré que sh exécute (sonde)' => [[['services.db.healthcheck.test', ['CMD-SHELL', "sh <<FIN\nmysqladmin ping -uroot --host=db \"-p\$\$MYSQL_ROOT_PASSWORD\"\nFIN"]]]],
    'un document intégré que sh exécute (commande)' => [[['services.redis.command', "sh <<FIN\nmysqladmin ping -uroot --host=db \"-p\$\$REDIS_PASSWORD\"\nFIN", true]]],
    'un document intégré non coté dont $(…) s’exécute (sonde)' => [[['services.db.healthcheck.test', ['CMD-SHELL', "cat <<FIN\n\$\$(mysqladmin ping -uroot -p\$\$MYSQL_ROOT_PASSWORD)\nFIN"]]]],
    'un document intégré non coté dont $(…) s’exécute (commande)' => [[['services.redis.command', "cat <<FIN\n\$\$(curl -u u:\$\$REDIS_PASSWORD http://h)\nFIN", true]]],
    'un document intégré donné à cat, dont la sortie va à sh' => [[['services.db.healthcheck.test', ['CMD-SHELL', "cat <<FIN | sh\nmysqladmin ping -uroot \"-p\$\$MYSQL_ROOT_PASSWORD\"\nFIN"]]]],
    'un document intégré donné à python3' => [[['services.db.healthcheck.test', ['CMD-SHELL', "python3 <<FIN\nimport os; os.system('mysqladmin ping -uroot -p\$\$MYSQL_ROOT_PASSWORD')\nFIN"]]]],
    'un document intégré donné à php' => [[['services.app.command', "php <<FIN\n<?php passthru('curl -u u:\$\$DB_PASSWORD http://h');\nFIN"]]],
    'un document intégré donné à perl' => [[['services.redis.command', "perl <<FIN\nsystem('curl -u u:\$\$REDIS_PASSWORD http://h');\nFIN", true]]],
    'un document intégré donné à node' => [[['services.redis.command', "node <<FIN\nrequire('child_process').execSync('curl -u u:\$\$REDIS_PASSWORD http://h');\nFIN", true]]],
    'un document intégré coté donné à sh' => [[['services.redis.command', "sh <<'FIN'\ncurl -u u:\$\$REDIS_PASSWORD http://h\nFIN", true]]],
    'un document intégré de Redis dont la ligne se prolonge par un tube' => [[['services.redis.command', "redis-cli <<FIN | sh\nAUTH \$\$REDIS_PASSWORD\nFIN", true]]],
    'B1 : un tube dont le deuxième maillon est xargs' => [[['services.redis.command', 'printf %s "$$REDIS_PASSWORD" | sed s/a/a/ | xargs -I{} curl -u u:{} http://h', true]]],
    'B1 : un tube dont le troisième maillon est xargs' => [[['services.redis.command', 'printf %s "$$REDIS_PASSWORD" | cat | xargs curl -u', true]]],
    'B1 : une affectation dont le deuxième maillon est xargs' => [[['services.redis.command', 'x=$$(printf %s "$$REDIS_PASSWORD" | sed s/a/a/ | xargs curl -u)', true]]],
    'un document intégré donné à xargs' => [[['services.redis.command', "xargs curl -u <<FIN\n\$\$REDIS_PASSWORD\nFIN", true]]],
    'A1 : le secret affecté puis passé en argument' => [[['services.redis.command', 'valeur=$$(printf %s "$$REDIS_PASSWORD"); curl -u "u:$$valeur" http://h', true]]],
    'A2 : printf devant xargs' => [[['services.redis.command', 'printf %s "$$REDIS_PASSWORD" | xargs -I{} curl -u u:{} http://h', true]]],
    'A3 : printenv' => [[['services.redis.command', 'curl -u "u:$$(printenv REDIS_PASSWORD)" http://h', true]]],
    'A10 : post_start' => [[['services.app.post_start', [['command' => 'curl -u u:${DB_PASSWORD} http://h']]]]],
    'A10 : pre_stop avec une référence du shell' => [[['services.app.pre_stop', [['command' => 'curl -u "u:$$DB_PASSWORD" http://h']]]]],
    'un nom en PW' => [[['services.redis.command', 'notifier "$$REDIS_PW"', true]]],
    'un nom en SECRETS' => [[['services.redis.command', 'notifier "$$APP_SECRETS"', true]]],
    'un nom en PASSPHRASE' => [[['services.redis.command', 'notifier "$$BACKUP_PASSPHRASE"', true]]],
    'une option --passphrase' => [[['services.redis.command', 'sauvegarde --passphrase abc', true]]],
    'une option de journalisation au nom d’un secret' => [[['services.app.logging', ['driver' => 'splunk', 'options' => ['splunk-token' => '${SPLUNK_VALEUR}']]]]],
    'un nom collé : GITHUBTOKEN' => [[['services.redis.command', 'notifier "$$GITHUBTOKEN"', true]]],
    'un nom collé : dbPassword' => [[['services.redis.command', 'notifier "$$dbPassword"', true]]],
    'une substitution affectée qui laisse aussi le secret à une autre commande' => [[['services.redis.command', 'x=$$(printf %s "$$REDIS_PASSWORD"; curl -u "$$REDIS_PASSWORD" http://h)', true]]],
    'env au milieu d’une commande' => [[['services.redis.command', 'logger env MYSQL_PWD="$$REDIS_PASSWORD"', true]]],
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
    'un secret dans le corps d’un document intégré' => [[['services.redis.command', "cat > /tmp/conf <<FIN\nrequirepass \"\$\$mot_de_passe\"\nFIN", true]]],
    'un document intégré coté, que le shell ne développe pas' => [[['services.redis.command', "cat > /tmp/conf <<'FIN'\n\$\$(echo \$\$mot_de_passe)\nFIN", true]]],
    'printf devant un lecteur sûr' => [[['services.redis.command', 'printf %s "$$REDIS_PASSWORD" | base64', true]]],
    'un tube dont tous les maillons sont sûrs' => [[['services.redis.command', 'printf %s "$$REDIS_PASSWORD" | sed s/a/b/ | base64 | wc -c', true]]],
    'un document intégré pour mysql' => [[['services.redis.command', "mysql -uroot <<FIN\nALTER USER x IDENTIFIED BY \"\$\$mot_de_passe\";\nFIN", true]]],
    'une variable en AUTH_MODE, qui n’est pas un secret' => [[['services.redis.command', 'notifier "$$MAIL_AUTH_MODE" "$$AUTH_ENABLED"', true]]],
    'une disposition de clavier en KEY_LAYOUT' => [[['services.redis.command', 'notifier "$$KEY_LAYOUT"', true]]],
    'un chemin de clé dans les options de journalisation' => [[['services.app.logging', ['driver' => 'syslog', 'options' => ['syslog-tls-key' => '/etc/ssl/k.pem']]]]],
    'une adresse dans les options de journalisation' => [[['services.app.logging', ['driver' => 'awslogs', 'options' => ['awslogs-credentials-endpoint' => 'https://exemple.org/c']]]]],
    'un argument de construction KEY_LAYOUT ou AUTH_ENABLED' => [[['services.app.build', ['context' => '.', 'args' => ['KEY_LAYOUT' => 'fr', 'AUTH_ENABLED' => '1']]]]],
    'une option de journalisation sans secret' => [[['services.app.logging', ['driver' => 'json-file', 'options' => ['max-size' => '10m']]]]],
    'mkdir -p avec une référence' => [[['services.redis.command', 'mkdir -p "$$DOSSIER"', true]]],
    'redis-cli -p avec une référence' => [[['services.redis.command', 'redis-cli -p "$$REDIS_PORT" ping', true]]],
    'un -P de port avec une référence' => [[['services.redis.command', 'mysql -P ${DB_PORT} -h db', true]]],
    'mkdir -p, qui n’est pas un mot de passe' => [[['services.redis.command', 'mkdir -p /data/dossier', true]]],
    'une étiquette et un argument de construction sans secret' => [[
        ['services.app.labels', ['com.exemple.version=${VERSION:-1}']],
        ['services.app.build', ['context' => '.', 'args' => ['NODE_VERSION' => '24']]],
    ]],
]);
