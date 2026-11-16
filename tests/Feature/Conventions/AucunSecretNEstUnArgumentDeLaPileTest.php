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
 * lecteur (`xargs`, `sh`, `curl`, `tee >(sh)`, `redis-cli`, `mysql`…) peut le
 * remettre en argument.
 */
const SECRETS_EN_ARGUMENT_LECTEURS_SURS = '(?:sed|tr|base64|wc|cat|head|tail|cut|openssl|sha256sum|sha1sum|md5sum)';

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
 * La base est la garde d'avant #2397 : toute référence à une variable qui porte
 * un secret est un défaut, et toute option qui prend un mot de passe aussi. Le
 * reste s'ajoute sans jamais admettre ce qu'elle refusait, hors les trois gestes
 * qui ne laissent pas la valeur dans une ligne de commande :
 * - `MYSQL_PWD="$$NOM"` en affectation, au début d'une commande ;
 * - `printf %s "$$NOM" |` à l'entrée d'un tube, au début d'une commande ou dans
 *   `VAR=$$(…)`, dont les maillons suivants sont tous des lecteurs sûrs (`sed`,
 *   `tr`, `base64`, `wc`, `cat`, `head`, `tail`, `cut`, `openssl`, `sha*sum`,
 *   `md5sum`) et qui finit la commande : seul `printf %s "$$NOM" |` est effacé,
 *   les options et les `$$(…)` des maillons restent contrôlés (`-a`, `-p`,
 *   `--pass`, `-pass`, `pass:`) ; `openssl -pass stdin` reste admis ;
 * - les références du shell (`$$NOM`) du corps du document intégré que lit
 *   `redis-server` (avec `exec` ou `docker-entrypoint.sh` devant), sur une ligne
 *   sans tube ni `;`, et non coté ni `$(` : `${NOM}`, interpolation de Compose
 *   écrite dans la configuration du conteneur, reste toujours un défaut.
 * Le nom affecté par `VAR=$$(printf %s "$$NOM" …)` devient lui-même un secret.
 * `redis-cli`, `mysql`, `cat > fichier` ne sont PAS admis : un fichier s'exécute
 * ou se source, `\!` et `system` du client MySQL exécutent.
 *
 * Ce qu'elle ne peut pas voir : un secret qui voyage dans une variable
 * intermédiaire à plusieurs pas, un lecteur sûr qui le remet en argument par
 * lui-même (`sed` avec `e`), les alias d'environnement à plus d'un niveau, un
 * secret construit par morceaux (`$$A$$B`), et ce qu'un script externe copié
 * dans l'image fait de son environnement. `docker inspect` montre de toute façon
 * l'environnement du conteneur.
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
        $lecteurs = SECRETS_EN_ARGUMENT_LECTEURS_SURS;
        $substitutions = [
            '__D__' => '(?:^|[;&|(\n])\s*(?:(?:env|export)\s+)?',
            '__R__' => '"\$\$\{?[A-Za-z0-9_]+\}?"',
            '__C__' => "(?=(?:\\s*{$lecteurs}\\b(?>(?:(?!&&)[^|;\\n)])*)\\|?)+\\s*(?:\\)|\\n|\$))",
        ];

        // Le document intégré de Redis, et lui seul : ses références du shell
        // s'effacent, l'interpolation de Compose reste vue.
        $reste = (string) preg_replace_callback(
            '/((?:[^;&|\n\\\\]|\\\\.)*)<<-?\s*(\w+)([^\n]*\n)(.*?)\n\s*\2\b/s',
            static fn (array $trouve): string => preg_match('/^\s*(?:exec\s+)?(?:docker-entrypoint\.sh\s+)?redis-server(?![\w.-])/', $trouve[1]) === 1
                && preg_match('/[|;]|&&/', $trouve[3]) === 0
                && preg_match('/\$\$\(|`/', $trouve[4]) === 0
                    ? $trouve[1].$trouve[3].preg_replace('/\$\$\{?[A-Za-z_]\w*\}?/', '', $trouve[4])
                    : $trouve[0],
            $reste,
        );

        preg_match_all(strtr('/__D__([A-Za-z_][A-Za-z0-9_]*)=\$\$\(\s*printf\s+%s\s+__R__/', $substitutions), $reste, $affectes);
        $secrets = [...$secrets, ...$affectes[1]];

        $reste = (string) preg_replace(
            array_map(static fn (string $motif): string => strtr($motif, $substitutions), [
                '/(__D__)MYSQL_PWD=__R__/',
                '/((?:^|[;&\n])\s*|[A-Za-z_][A-Za-z0-9_]*=\$\$\(\s*)printf\s+%s\s+__R__\s*\|__C__/',
            ]),
            ['$1', '$1'],
            $reste,
        );
    }

    preg_match_all('/\$\$?\{?([A-Za-z_][A-Za-z0-9_]*)|\bprintenv\s+([A-Za-z_][A-Za-z0-9_]*)/', $reste, $references);

    foreach (array_unique(array_filter([...$references[1], ...$references[2]], static fn (string $nom): bool => $nom !== '')) as $nom) {
        if (in_array($nom, $secrets, true) || preg_match(SECRETS_EN_ARGUMENT_MOTIF, $nom) === 1) {
            $defauts[] = "référence à {$nom}";
        }
    }

    $mysql = SECRETS_EN_ARGUMENT_BINAIRES_MYSQL;
    $longues = '(?:^|\s)--(?:(?:requirepass|masterauth|password|pass|auth)\b|(?:passwd|passphrase|secret|token|jeton|cle|key|api-key|mot-de-passe)(?=[=\s]|$))';
    $courtes = '(?:^|\s)(?:-a\s|-p(?!ass(?:in|out)?\s+["\']?(?:stdin|env:|file:|fd:))\S)';
    $openssl = '(?<![\w-])pass:|(?:^|\s)-pass(?:in|out)?\s+(?!["\']?(?:stdin|env:|file:|fd:))\S';
    // `-p <valeur>` de la famille MySQL, refusé par prudence, d'une ligne de
    // shell comme d'une liste (dont les éléments se joignent par des retours
    // à la ligne).
    $apres = "/\\b{$mysql}\\b[^|;&]*?\\s-p\\s+\\S/";

    if (preg_match("/{$longues}|{$courtes}/i", $reste) === 1 || preg_match("/{$openssl}/", $reste) === 1 || preg_match($apres, $reste) === 1) {
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
    'un document intégré donné à python3' => [[['services.db.healthcheck.test', ['CMD-SHELL', "python3 <<FIN\nprint('-p\$\$MYSQL_ROOT_PASSWORD')\nFIN"]]]],
    'un document intégré donné à php' => [[['services.app.command', "php <<FIN\n<?php echo 'u:\$\$DB_PASSWORD';\nFIN"]]],
    'un document intégré donné à perl' => [[['services.redis.command', "perl <<FIN\nprint 'u:\$\$REDIS_PASSWORD';\nFIN", true]]],
    'un document intégré donné à node' => [[['services.redis.command', "node <<FIN\nconsole.log('u:\$\$REDIS_PASSWORD');\nFIN", true]]],
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
    'printf devant redis-cli, dont l’option garde le secret' => [[['services.redis.healthcheck.test', ['CMD-SHELL', 'printf %s "$$REDIS_PASSWORD" | redis-cli -a "$$(cat)" ping']]]],
    'printf devant mysql, dont l’option garde le secret' => [[['services.db.healthcheck.test', ['CMD-SHELL', 'printf %s "$$MYSQL_ROOT_PASSWORD" | mysql -uroot -p"$$(cat)" -e "select 1"']]]],
    'printf, sed puis redis-cli --pass' => [[['services.redis.healthcheck.test', ['CMD-SHELL', 'printf %s "$$REDIS_PASSWORD" | sed s/a/a/ | redis-cli --pass "$$(cat)" ping']]]],
    'printf devant openssl -pass pass:' => [[['services.redis.command', 'printf %s "$$REDIS_PASSWORD" | openssl enc -pass "pass:$$(cat)" -in /data/f', true]]],
    'une option --token' => [[['services.redis.command', 'notifier --token abc', true]]],
]);

it('admet, sur la vraie composition, ce qui n’est pas un secret en argument', function (array $mutations): void {
    expect(secretsEnArgumentDefautsDeLaComposition(secretsEnArgumentCompositionMutee($mutations)))->toBe([]);
})->with([
    'printf devant un tube, en début de commande' => [[['services.redis.command', 'printf %s "$$REDIS_PASSWORD" | wc -c', true]]],
    'printf dans une affectation' => [[['services.redis.command', 'longueur=$$(printf %s "$$REDIS_PASSWORD" | wc -c)', true]]],
    'MYSQL_PWD en affectation derrière env' => [[['services.db.healthcheck.test', ['CMD-SHELL', 'env MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" mysqladmin ping']]]],
    'openssl lit le secret sur son entrée' => [[['services.redis.command', 'printf %s "$$REDIS_PASSWORD" | openssl enc -aes256 -pass stdin -in /data/f', true]]],
    'le document intégré réel de Redis, ses références du shell effacées' => [[['services.redis.command', ['sh', '-c', "exec docker-entrypoint.sh redis-server - --appendonly yes <<CONFIGURATION\nrequirepass \"\$\$REDIS_PASSWORD\"\nCONFIGURATION"]]]],
    'printf devant un lecteur sûr' => [[['services.redis.command', 'printf %s "$$REDIS_PASSWORD" | base64', true]]],
    'un tube dont tous les maillons sont sûrs' => [[['services.redis.command', 'printf %s "$$REDIS_PASSWORD" | sed s/a/b/ | base64 | wc -c', true]]],
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

it('refuse encore tout ce que la garde d’avant #2397 refusait (et les contournements relevés après)', function (array $mutations): void {
    expect(secretsEnArgumentDefautsDeLaComposition(secretsEnArgumentCompositionMutee($mutations)))->not->toBe([]);
})->with([
    'un document intégré que sh exécute (sonde)' => [[
        0 => [
            0 => 'services.db.healthcheck.test',
            1 => [
                0 => 'CMD-SHELL',
                1 => 'sh <<FIN
mysqladmin ping -uroot --host=db "-p$$MYSQL_ROOT_PASSWORD"
FIN',
            ],
        ],
    ]],
    'un document intégré que sh exécute (commande)' => [[
        0 => [
            0 => 'services.redis.command',
            1 => 'sh <<FIN
mysqladmin ping -uroot --host=db "-p$$REDIS_PASSWORD"
FIN',
            2 => true,
        ],
    ]],
    'un document intégré non coté dont $(…) s’exécute (sonde)' => [[
        0 => [
            0 => 'services.db.healthcheck.test',
            1 => [
                0 => 'CMD-SHELL',
                1 => 'cat <<FIN
$$(mysqladmin ping -uroot -p$$MYSQL_ROOT_PASSWORD)
FIN',
            ],
        ],
    ]],
    'un document intégré non coté dont $(…) s’exécute (commande)' => [[
        0 => [
            0 => 'services.redis.command',
            1 => 'cat <<FIN
$$(curl -u u:$$REDIS_PASSWORD http://h)
FIN',
            2 => true,
        ],
    ]],
    'un document intégré donné à cat, dont la sortie va à sh' => [[
        0 => [
            0 => 'services.db.healthcheck.test',
            1 => [
                0 => 'CMD-SHELL',
                1 => 'cat <<FIN | sh
mysqladmin ping -uroot "-p$$MYSQL_ROOT_PASSWORD"
FIN',
            ],
        ],
    ]],
    'un document intégré donné à python3' => [[
        0 => [
            0 => 'services.db.healthcheck.test',
            1 => [
                0 => 'CMD-SHELL',
                1 => 'python3 <<FIN
x = (\'-p$$MYSQL_ROOT_PASSWORD\')
FIN',
            ],
        ],
    ]],
    'un document intégré donné à php' => [[
        0 => [
            0 => 'services.app.command',
            1 => 'php <<FIN
<?php echo \'u:$$DB_PASSWORD\';
FIN',
        ],
    ]],
    'un document intégré donné à perl' => [[
        0 => [
            0 => 'services.redis.command',
            1 => 'perl <<FIN
$x = \'u:$$REDIS_PASSWORD\';
FIN',
            2 => true,
        ],
    ]],
    'un document intégré donné à node' => [[
        0 => [
            0 => 'services.redis.command',
            1 => 'node <<FIN
console.log(\'u:$$REDIS_PASSWORD\');
FIN',
            2 => true,
        ],
    ]],
    'un document intégré coté donné à sh' => [[
        0 => [
            0 => 'services.redis.command',
            1 => 'sh <<\'FIN\'
curl -u u:$$REDIS_PASSWORD http://h
FIN',
            2 => true,
        ],
    ]],
    'un document intégré de Redis dont la ligne se prolonge par un tube' => [[
        0 => [
            0 => 'services.redis.command',
            1 => 'redis-cli <<FIN | sh
AUTH $$REDIS_PASSWORD
FIN',
            2 => true,
        ],
    ]],
    'un document intégré donné à xargs' => [[
        0 => [
            0 => 'services.redis.command',
            1 => 'xargs curl -u <<FIN
$$REDIS_PASSWORD
FIN',
            2 => true,
        ],
    ]],
    'un nom collé : GITHUBTOKEN' => [[
        0 => [
            0 => 'services.redis.command',
            1 => 'notifier "$$GITHUBTOKEN"',
            2 => true,
        ],
    ]],
    'un nom collé : dbPassword' => [[
        0 => [
            0 => 'services.redis.command',
            1 => 'notifier "$$dbPassword"',
            2 => true,
        ],
    ]],
    'une substitution affectée qui laisse aussi le secret à une autre commande' => [[
        0 => [
            0 => 'services.redis.command',
            1 => 'x=$$(printf %s "$$REDIS_PASSWORD"; curl -u "$$REDIS_PASSWORD" http://h)',
            2 => true,
        ],
    ]],
    'C1 interpolation Compose dans le heredoc de Redis' => [[
        0 => [
            0 => 'services.redis.command',
            1 => [
                0 => 'sh',
                1 => '-c',
                2 => 'exec docker-entrypoint.sh redis-server - --appendonly yes <<CONFIGURATION
requirepass "${REDIS_PASSWORD}"
CONFIGURATION',
            ],
        ],
    ]],
    'C1b interpolation Compose, cat > fichier' => [[
        0 => [
            0 => 'services.redis.command',
            1 => [
                0 => 'sh',
                1 => '-c',
                2 => 'cat > /tmp/r.conf <<FIN
requirepass ${REDIS_PASSWORD}
FIN
exec redis-server /tmp/r.conf',
            ],
        ],
    ]],
    'C2 script ecrit par cat > puis execute' => [[
        0 => [
            0 => 'services.redis.command',
            1 => [
                0 => 'sh',
                1 => '-c',
                2 => 'cat > /tmp/x.sh <<FIN
curl -u "u:$$REDIS_PASSWORD" http://h
FIN
sh /tmp/x.sh',
            ],
        ],
    ]],
    'C3 mysql heredoc avec \\! (commande systeme du client)' => [[
        0 => [
            0 => 'services.db.healthcheck.test',
            1 => [
                0 => 'CMD-SHELL',
                1 => 'mysql -uroot <<FIN
\\! mysqladmin ping -uroot -p$$MYSQL_ROOT_PASSWORD
FIN',
            ],
        ],
    ]],
    'C4 printf | redis-cli -a "$(cat)"' => [[
        0 => [
            0 => 'services.redis.healthcheck.test',
            1 => [
                0 => 'CMD-SHELL',
                1 => 'printf %s "$$REDIS_PASSWORD" | redis-cli -a "$$(cat)" ping',
            ],
        ],
    ]],
    'C5 printf | mysql -p"$(cat)"' => [[
        0 => [
            0 => 'services.db.healthcheck.test',
            1 => [
                0 => 'CMD-SHELL',
                1 => 'printf %s "$$MYSQL_ROOT_PASSWORD" | mysql -uroot -p"$$(cat)" -e \'select 1\'',
            ],
        ],
    ]],
    'C6 printf | sed | redis-cli --pass "$(cat)"' => [[
        0 => [
            0 => 'services.redis.healthcheck.test',
            1 => [
                0 => 'CMD-SHELL',
                1 => 'printf %s "$$REDIS_PASSWORD" | sed s/a/a/ | redis-cli --pass "$$(cat)" ping',
            ],
        ],
    ]],
    'C7 printf | openssl -pass pass:$(cat)' => [[
        0 => [
            0 => 'services.redis.command',
            1 => [
                0 => 'sh',
                1 => '-c',
                2 => 'printf %s "$$REDIS_PASSWORD" | openssl enc -aes256 -pass "pass:$$(cat)" -in /data/f',
            ],
        ],
    ]],
    'C8 printf | tee >(sh)' => [[
        0 => [
            0 => 'services.redis.command',
            1 => [
                0 => 'sh',
                1 => '-c',
                2 => 'printf \'curl -u u:%s h\' "$$REDIS_PASSWORD" | tee >(sh)',
            ],
        ],
    ]],
    'C9 printf %s | head -c 99 puis xargs (&& sh)' => [[
        0 => [
            0 => 'services.redis.command',
            1 => [
                0 => 'sh',
                1 => '-c',
                2 => 'printf %s "$$REDIS_PASSWORD" | cat > /tmp/p && xargs curl -u < /tmp/p',
            ],
        ],
    ]],
    'C10 redis-server heredoc puis ; sh' => [[
        0 => [
            0 => 'services.redis.command',
            1 => [
                0 => 'sh',
                1 => '-c',
                2 => 'redis-server - <<FIN ; curl -u u:$$REDIS_PASSWORD h
requirepass x
FIN',
            ],
        ],
    ]],
    'C11 cat > >(sh) heredoc (bash)' => [[
        0 => [
            0 => 'services.redis.command',
            1 => [
                0 => 'bash',
                1 => '-c',
                2 => 'cat > >(sh) <<FIN
curl -u "u:$$REDIS_PASSWORD" http://h
FIN',
            ],
        ],
    ]],
    'C12 heredoc <<- tabule vers redis-cli puis xargs ligne suivante' => [[
        0 => [
            0 => 'services.redis.command',
            1 => [
                0 => 'sh',
                1 => '-c',
                2 => 'redis-cli <<-FIN
AUTH $$REDIS_PASSWORD
FIN
true',
            ],
        ],
    ]],
    'C13 mysql heredoc avec system' => [[
        0 => [
            0 => 'services.db.healthcheck.test',
            1 => [
                0 => 'CMD-SHELL',
                1 => 'mysql -uroot <<FIN
system curl -u u:$$MYSQL_ROOT_PASSWORD http://h
FIN',
            ],
        ],
    ]],
    'C14 cat > fichier .env source' => [[
        0 => [
            0 => 'services.redis.command',
            1 => [
                0 => 'sh',
                1 => '-c',
                2 => 'cat > /tmp/e <<FIN
export X=$$REDIS_PASSWORD
FIN
. /tmp/e',
            ],
        ],
    ]],
    'C15 compose interpolation dans heredoc mysql' => [[
        0 => [
            0 => 'services.db.healthcheck.test',
            1 => [
                0 => 'CMD-SHELL',
                1 => 'mysql -uroot <<FIN
SELECT \'${DB_PASSWORD}\';
FIN',
            ],
        ],
    ]],
    'B2 tube enchaine : cat puis sh' => [[
        0 => [
            0 => 'services.redis.command',
            1 => 'printf \'curl -u u:%s http://h\' "$$REDIS_PASSWORD" | cat | sh',
            2 => true,
        ],
    ]],
    'B3 heredoc pipe vers sh (cat <<FIN | sh)' => [[
        0 => [
            0 => 'services.redis.command',
            1 => 'cat <<FIN | sh
curl -u "u:$$REDIS_PASSWORD" http://h
FIN',
            2 => true,
        ],
    ]],
    'B4 heredoc a python3' => [[
        0 => [
            0 => 'services.redis.command',
            1 => 'python3 <<FIN
import subprocess; subprocess.run([\'curl\',\'-u\',\'u:$$REDIS_PASSWORD\',\'http://h\'])
FIN',
            2 => true,
        ],
    ]],
    'B5 heredoc a php' => [[
        0 => [
            0 => 'services.app.command',
            1 => 'php <<FIN
<?php passthru(\'curl -u u:$$DB_PASSWORD http://h\');
FIN',
        ],
    ]],
    'B9 /bin/sh heredoc' => [[
        0 => [
            0 => 'services.redis.command',
            1 => '/bin/sh <<FIN
curl -u "u:$$REDIS_PASSWORD" http://h
FIN',
            2 => true,
        ],
    ]],
    'B10 heredoc tee >(sh)' => [[
        0 => [
            0 => 'services.redis.command',
            1 => 'tee >(sh) <<FIN
curl -u "u:$$REDIS_PASSWORD" http://h
FIN',
            2 => true,
        ],
    ]],
    'B3 sonde : cat <<FIN | sh' => [[
        0 => [
            0 => 'services.db.healthcheck.test',
            1 => [
                0 => 'CMD-SHELL',
                1 => 'cat <<FIN | sh
mysqladmin ping -uroot "-p$$MYSQL_ROOT_PASSWORD"
FIN',
            ],
        ],
    ]],
    'B4 sonde : python3 <<FIN' => [[
        0 => [
            0 => 'services.db.healthcheck.test',
            1 => [
                0 => 'CMD-SHELL',
                1 => 'python3 <<FIN
import os; os.system(\'mysqladmin ping -uroot -p$$MYSQL_ROOT_PASSWORD\')
FIN',
            ],
        ],
    ]],
    'B1 sonde : printf | sed | xargs' => [[
        0 => [
            0 => 'services.db.healthcheck.test',
            1 => [
                0 => 'CMD-SHELL',
                1 => 'printf %s "$$MYSQL_ROOT_PASSWORD" | sed s/x/x/ | xargs -I{} mysqladmin ping -uroot -p{}',
            ],
        ],
    ]],
]);
