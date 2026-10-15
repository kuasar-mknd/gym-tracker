<?php

declare(strict_types=1);

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\DuskTestCase;

/*
 * Chaque éclat Dusk payait le premier démarrage de Chrome dans son premier
 * parcours : neuf à douze secondes de plus que les suivants sur un éclat vert.
 * Le 12/10, ce démarrage a pris 48 s, puis la première visite est restée
 * soixante secondes sans réponse du rendu et le parcours est tombé (#2046),
 * comme le premier parcours de l'éclat 2 le 08/10 (#2029). Chaque job qui
 * lance les parcours chauffe donc Chrome avant eux, par le pilote même que
 * les parcours emploieront : une session ouverte avec les options sans tête
 * et la borne d'une page de DuskTestCase, une page http rendue, une capture,
 * la fermeture. Le pilote coupe lui-même un lancement de Chrome et une page à
 * soixante secondes, et répond alors par une erreur : la chauffe rejoue
 * l'ordre refusé tant qu'elle a du temps, un nombre de fois borné. Elle écrit
 * ses durées, et ne fait jamais tomber le job.
 */

/**
 * Les jobs des workflows qui lancent les parcours (`artisan dusk`, pas
 * `artisan dusk:chrome-driver`), avec leurs étapes dans l'ordre.
 *
 * @return list<array{ou: string, etapes: list<array<mixed>>}>
 */
function chromeChaudJobsDesParcours(): array
{
    $jobs = [];

    foreach (['yml', 'yaml'] as $extension) {
        $chemins = glob(base_path(".github/workflows/*.{$extension}"));

        foreach ($chemins === false ? [] : $chemins as $chemin) {
            $definitions = data_get(Yaml::parseFile($chemin), 'jobs', []);

            foreach (is_array($definitions) ? $definitions : [] as $nom => $definition) {
                $etapes = data_get($definition, 'steps', []);
                $etapes = array_values(array_filter(is_array($etapes) ? $etapes : [], is_array(...)));

                if (array_filter($etapes, chromeChaudLanceLesParcours(...)) !== []) {
                    $jobs[] = ['ou' => basename($chemin)." > {$nom}", 'etapes' => $etapes];
                }
            }
        }
    }

    return $jobs;
}

/**
 * @param  array<mixed>  $etape
 */
function chromeChaudLanceLesParcours(array $etape): bool
{
    $script = $etape['run'] ?? null;

    return is_string($script) && preg_match('/\bartisan\s+dusk(?![:\w-])/', $script) === 1;
}

/**
 * Le rang de la première étape qui répond à la condition, ou null.
 *
 * @param  list<array<mixed>>  $etapes
 * @param  Closure(array<mixed>): bool  $condition
 */
function chromeChaudRangDe(array $etapes, Closure $condition): ?int
{
    foreach ($etapes as $rang => $etape) {
        if ($condition($etape)) {
            return $rang;
        }
    }

    return null;
}

/**
 * Le rang de l'étape de chauffe d'un job.
 *
 * @param  list<array<mixed>>  $etapes
 */
function chromeChaudRangDeLaChauffe(array $etapes, string $ou): int
{
    $rang = chromeChaudRangDe($etapes, static fn (array $etape): bool => ($etape['name'] ?? null) === 'Chauffer Chrome');

    if ($rang === null) {
        throw new RuntimeException("{$ou} lance les parcours sans étape « Chauffer Chrome » : son premier parcours paierait le premier démarrage de Chrome (#2046).");
    }

    return $rang;
}

/**
 * Le port sur lequel une étape démarre ChromeDriver, ou null quand elle ne le
 * démarre pas. Le script se lit comme le shell le lit : commentaires ôtés,
 * lignes continuées par `\` jointes. Une commande `chromedriver…` (un chemin
 * ou un nom) démarre le pilote ; une mention de son journal
 * (`chromedriver.log`) ou `artisan dusk:chrome-driver`, qui l'installe, non.
 * Sans `--port`, ChromeDriver écoute sur 9515. Un `--port` sans `=`
 * (`--port 9515`) l'arrête (« Invalid port. Exiting... »), et un port qui ne
 * se lit pas (une variable) ne se compare à rien : le port rendu est alors 0,
 * qu'aucun pilote des parcours ne vise.
 *
 * @param  array<mixed>  $etape
 */
function chromeChaudPortDuPiloteDemarre(array $etape): ?int
{
    $script = $etape['run'] ?? null;

    if (! is_string($script)) {
        return null;
    }

    $script = (string) preg_replace(['/(?<=^|\s)#.*$/m', '/\\\\\r?\n/'], ['', ' '], $script);

    if (preg_match('~(?<![\w./=-])(?:[\w.-]*/)*chromedriver(?:-[\w-]+)?(?![\w./-])([^;&|\n]*)~', $script, $commande) !== 1) {
        return null;
    }

    if (preg_match('/(?<!\S)--port(?:=(\S*))?(?!\S)/', $commande[1], $option) !== 1) {
        return 9515;
    }

    return ctype_digit($option[1] ?? '') ? (int) $option[1] : 0;
}

/**
 * Les capacités que les parcours demandent au pilote, avec ou sans tête.
 */
function chromeChaudCapacitesDesParcours(bool $sansTete): DesiredCapabilities
{
    $parcours = new class('parcoursFictif') extends DuskTestCase
    {
        public bool $sansTete = true;

        public function parcoursFictif(): void
        {
        }

        public function capacites(): DesiredCapabilities
        {
            return $this->capacitesDuNavigateur();
        }

        #[\Override]
        protected function hasHeadlessDisabled(): bool
        {
            return ! $this->sansTete;
        }
    };

    $parcours->sansTete = $sansTete;

    return $parcours->capacites();
}

/**
 * Les options que les parcours ne passent à Chrome que sans tête, comme en
 * CI : celles qui changent la façon dont il démarre et dont il rend.
 *
 * @return list<string>
 */
function chromeChaudOptionsSansTeteDesParcours(): array
{
    $arguments = static function (bool $sansTete): array {
        $options = chromeChaudCapacitesDesParcours($sansTete)->getCapability(ChromeOptions::CAPABILITY);
        $arguments = $options instanceof ChromeOptions ? (((array) $options->toArray())['args'] ?? []) : [];

        return array_values(array_filter(is_array($arguments) ? $arguments : [], is_string(...)));
    };

    return array_values(array_diff($arguments(true), $arguments(false)));
}

/**
 * Le temps qu'une page des parcours a pour se charger, en millisecondes.
 */
function chromeChaudBorneDUnePageDesParcours(): int
{
    $borne = data_get(chromeChaudCapacitesDesParcours(true)->getCapability('timeouts'), 'pageLoad');

    return is_int($borne) ? $borne : throw new RuntimeException('Les parcours ne bornent plus le chargement d\'une page : la chauffe ne peut plus s\'y aligner.');
}

/**
 * Ce qui ne va pas dans la chauffe d'un job qui lance les parcours, une phrase
 * par défaut, rien quand elle est en ordre. Le démarrage du pilote doit être
 * reconnu : un démarrage écrit autrement ne laisse pas passer une chauffe
 * placée avant lui, qui attendrait sa borne entière un pilote pas encore
 * lancé.
 *
 * @param  list<array<mixed>>  $etapes
 * @return list<string>
 */
function chromeChaudDefautsDuJob(string $ou, array $etapes): array
{
    $rangDeLaChauffe = chromeChaudRangDe($etapes, static fn (array $etape): bool => ($etape['name'] ?? null) === 'Chauffer Chrome');

    if ($rangDeLaChauffe === null) {
        return ["{$ou} lance les parcours sans étape « Chauffer Chrome » : son premier parcours paierait le premier démarrage de Chrome (#2046)."];
    }

    $chauffe = $etapes[$rangDeLaChauffe];
    $rangDesParcours = (int) chromeChaudRangDe($etapes, chromeChaudLanceLesParcours(...));
    $script = $etapes[$rangDesParcours]['run'] ?? '';
    $pilote = data_get($etapes[$rangDesParcours], 'env.DUSK_DRIVER_URL') ?? (is_string($script) && preg_match('/^\s*DUSK_DRIVER_URL=(\S+)/m', $script, $trouve) === 1 ? $trouve[1] : null);
    $rangDuPilote = chromeChaudRangDe($etapes, static fn (array $etape): bool => chromeChaudPortDuPiloteDemarre($etape) !== null);
    $portDuPilote = $rangDuPilote === null ? null : chromeChaudPortDuPiloteDemarre($etapes[$rangDuPilote]);
    $secondes = data_get($chauffe, 'env.SECONDES_DE_CHAUFFE');
    $essais = data_get($chauffe, 'env.ESSAIS_PAR_ORDRE');
    $minutes = data_get($chauffe, 'timeout-minutes');
    $secondesParPage = intdiv(chromeChaudBorneDUnePageDesParcours(), 1000);

    $defauts = [
        'la chauffe vient après les parcours' => $rangDeLaChauffe > $rangDesParcours,
        'la chauffe ne doit dépendre d\'aucune condition (`if:`), chaque éclat la paie' => array_key_exists('if', $chauffe),
        'une chauffe qui échoue ne doit pas faire tomber le job (`continue-on-error: true`), le premier parcours fera son propre diagnostic' => data_get($chauffe, 'continue-on-error') !== true,
        'la chauffe doit passer par le pilote des parcours (PILOTE égal à DUSK_DRIVER_URL)' => ! is_string($pilote) || data_get($chauffe, 'env.PILOTE') !== $pilote,
        'SECONDES_DE_CHAUFFE doit être un nombre entier de secondes, positif' => ! is_int($secondes) || $secondes < 1,
        'ESSAIS_PAR_ORDRE doit être un nombre entier d\'essais, deux au moins' => ! is_int($essais) || $essais < 2,
        'ESSAIS_PAR_ORDRE essais d\'une page des parcours doivent couvrir SECONDES_DE_CHAUFFE : le pilote coupe un lancement de Chrome et une page à soixante secondes' => is_int($secondes) && is_int($essais) && $essais * $secondesParPage < $secondes,
        'la borne de l\'étape (`timeout-minutes`) doit laisser la chauffe finir sur la sienne, fermer sa session et écrire ses durées' => ! is_int($minutes) || ! is_int($secondes) || $minutes * 60 <= $secondes + 30,
        'aucune étape ne démarre ChromeDriver (une commande `chromedriver…` dans un `run:`) : la chauffe attendrait jusqu\'à sa borne un pilote que rien ne lance' => $rangDuPilote === null,
        'le démarrage du pilote ne doit dépendre d\'aucune condition (`if:`) : sauté, il laisserait la chauffe l\'attendre jusqu\'à sa borne' => $rangDuPilote !== null && array_key_exists('if', $etapes[$rangDuPilote]),
        'la chauffe part avant le démarrage du pilote, qu\'elle attendrait jusqu\'à sa borne' => $rangDuPilote !== null && $rangDuPilote > $rangDeLaChauffe,
        'la chauffe et les parcours doivent viser le port du pilote que le job démarre' => $portDuPilote !== null && is_string($pilote) && $portDuPilote !== (parse_url($pilote, PHP_URL_PORT) ?? 80),
    ];

    return array_map(static fn (string $defaut): string => "{$ou} : {$defaut}", array_keys(array_filter($defauts)));
}

/**
 * Le rang de l'étape nommée d'un job. Une étape qui manque arrête la variante
 * en cours : elle ne doit pas passer pour une variante sans rien changer.
 *
 * @param  list<array<mixed>>  $etapes
 */
function chromeChaudRangDeLEtape(array $etapes, string $nom): int
{
    return chromeChaudRangDe($etapes, static fn (array $etape): bool => ($etape['name'] ?? null) === $nom)
        ?? throw new RuntimeException("étape « {$nom} » introuvable");
}

/**
 * Les étapes d'un job changées selon `$variante` : `demarrage` réécrit le
 * script du démarrage du pilote (false retire l'étape), `demarrageSi` lui pose
 * une condition, `chauffe` remplace des clés de la chauffe, `chauffeAvant` la
 * déplace juste avant l'étape nommée, `port` porte le pilote des parcours et
 * celui de la chauffe sur un autre port.
 *
 * @param  list<array<mixed>>  $etapes
 * @param  array<mixed>  $variante
 * @return list<array<mixed>>
 */
function chromeChaudVarianteDuJob(array $etapes, array $variante): array
{
    $rangDeLaChauffe = chromeChaudRangDeLEtape($etapes, 'Chauffer Chrome');
    $chauffe = $variante['chauffe'] ?? [];
    $etapes[$rangDeLaChauffe] = array_replace_recursive($etapes[$rangDeLaChauffe], is_array($chauffe) ? $chauffe : []);
    $port = $variante['port'] ?? null;

    if (is_int($port)) {
        $rangDesParcours = chromeChaudRangDeLEtape($etapes, 'Run Dusk Tests');
        $script = $etapes[$rangDesParcours]['run'] ?? null;
        $autre = is_string($script) ? str_replace('DUSK_DRIVER_URL=http://127.0.0.1:9515', "DUSK_DRIVER_URL=http://127.0.0.1:{$port}", $script) : $script;
        $etapes[$rangDesParcours]['run'] = $autre !== $script ? $autre : throw new RuntimeException('DUSK_DRIVER_URL introuvable');
        $etapes[$rangDeLaChauffe] = array_replace_recursive($etapes[$rangDeLaChauffe], ['env' => ['PILOTE' => "http://127.0.0.1:{$port}"]]);
    }

    $demarrage = $variante['demarrage'] ?? null;

    if (is_string($demarrage)) {
        $etapes[chromeChaudRangDeLEtape($etapes, 'Start Chrome Driver')]['run'] = $demarrage;
    }

    if ($demarrage === false) {
        array_splice($etapes, chromeChaudRangDeLEtape($etapes, 'Start Chrome Driver'), 1);
    }

    $condition = $variante['demarrageSi'] ?? null;

    if (is_string($condition)) {
        $etapes[chromeChaudRangDeLEtape($etapes, 'Start Chrome Driver')]['if'] = $condition;
    }

    $chauffeAvant = $variante['chauffeAvant'] ?? null;

    if (is_string($chauffeAvant)) {
        $chauffeDeplacee = array_splice($etapes, chromeChaudRangDeLEtape($etapes, 'Chauffer Chrome'), 1);
        array_splice($etapes, chromeChaudRangDeLEtape($etapes, $chauffeAvant), 0, $chauffeDeplacee);
    }

    return array_values($etapes);
}

/**
 * Lit l'ordre qu'un client envoie au faux pilote.
 *
 * @param  resource  $connexion
 * @return array{methode: string, chemin: string, corps: mixed}
 */
function chromeChaudOrdreRecu($connexion): array
{
    stream_set_timeout($connexion, 2);
    $requete = '';

    while (! str_contains($requete, "\r\n\r\n")) {
        $morceau = fread($connexion, 8192);

        if ($morceau === false || $morceau === '') {
            break;
        }

        $requete .= $morceau;
    }

    [$entetes, $corps] = explode("\r\n\r\n", $requete, 2) + [1 => ''];
    $longueur = preg_match('/^Content-Length:\s*(\d+)/mi', $entetes, $trouve) === 1 ? (int) $trouve[1] : 0;

    while (strlen($corps) < $longueur) {
        $morceau = fread($connexion, 8192);

        if ($morceau === false || $morceau === '') {
            break;
        }

        $corps .= $morceau;
    }

    $ligne = explode(' ', explode("\r\n", $entetes, 2)[0]);

    return [
        'methode' => $ligne[0],
        'chemin' => $ligne[1] ?? '',
        'corps' => $corps === '' ? null : json_decode($corps, true),
    ];
}

/**
 * Rejoue l'étape de chauffe comme GitHub la lance (`bash -e`), contre un faux
 * pilote qui répond à chaque ordre le statut et le corps que `$repondre` rend
 * (avec le rang de l'ordre parmi ceux de même méthode et de même chemin), ou
 * jamais quand il rend null. Comme ChromeDriver, il traite les ordres d'une
 * session l'un après l'autre : derrière un ordre resté sans réponse, ceux de
 * la même session attendent aussi, fermeture comprise. Sans faux pilote, la
 * chauffe vise le port 1, où rien n'écoute. Les chaînes de `$variables`
 * prennent le pas sur l'environnement de l'étape.
 *
 * @param  array<mixed>  $etape
 * @param  (Closure(string, string, int): ?array{0: int, 1: string})|null  $repondre
 * @param  array<mixed>  $variables
 * @return array{code: int|null, sortie: string, duree: float, ordres: list<array{methode: string, chemin: string, corps: mixed}>}
 */
function chromeChaudRejouerLaChauffe(array $etape, int $secondes, ?Closure $repondre, array $variables = []): array
{
    $ecoute = null;
    $adresse = 'http://127.0.0.1:1';

    if ($repondre !== null) {
        $ecoute = stream_socket_server('tcp://127.0.0.1:0', $codeDErreur, $erreur);

        if ($ecoute === false) {
            throw new RuntimeException("faux pilote impossible : {$erreur}");
        }

        $adresse = 'http://'.stream_socket_get_name($ecoute, false);
    }

    $environnement = ['PILOTE' => $adresse, 'SECONDES_DE_CHAUFFE' => (string) $secondes];

    foreach ($variables as $nom => $valeur) {
        if (is_string($nom) && is_string($valeur)) {
            $environnement[$nom] = $valeur;
        }
    }

    $declare = data_get($etape, 'env', []);

    foreach (is_array($declare) ? $declare : [] as $nom => $valeur) {
        if (is_string($nom) && is_scalar($valeur)) {
            $environnement[$nom] ??= (string) $valeur;
        }
    }

    $processus = new Process(
        ['bash', '-e', '-c', is_string($etape['run'] ?? null) ? $etape['run'] : 'exit 99'],
        base_path(),
        $environnement,
        timeout: 15,
    );
    $ordres = [];
    $recus = [];
    $muettes = [];
    $sessionsEnAttente = [];
    $debut = microtime(true);

    try {
        $processus->start();

        while ($processus->isRunning()) {
            $processus->checkTimeout();

            if ($ecoute === null || $repondre === null) {
                usleep(50_000);

                continue;
            }

            $lecture = [$ecoute];
            $ecriture = null;
            $exceptions = null;

            if (stream_select($lecture, $ecriture, $exceptions, 0, 50_000) < 1) {
                continue;
            }

            $connexion = stream_socket_accept($ecoute, 1);

            if ($connexion === false) {
                continue;
            }

            $ordre = chromeChaudOrdreRecu($connexion);
            $ordres[] = $ordre;
            $session = preg_match('#^/session/([^/]+)#', $ordre['chemin'], $trouve) === 1 ? $trouve[1] : '';

            if (isset($sessionsEnAttente[$session])) {
                $muettes[] = $connexion;

                continue;
            }

            $cle = "{$ordre['methode']} {$ordre['chemin']}";
            $recus[$cle] = ($recus[$cle] ?? 0) + 1;
            $reponse = $repondre($ordre['methode'], $ordre['chemin'], $recus[$cle]);

            if ($reponse === null) {
                $muettes[] = $connexion;

                if ($session !== '') {
                    $sessionsEnAttente[$session] = true;
                }

                continue;
            }

            [$statut, $corps] = $reponse;
            $raison = $statut === 200 ? 'OK' : 'Internal Server Error';
            fwrite($connexion, "HTTP/1.1 {$statut} {$raison}\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: ".strlen($corps)."\r\nConnection: close\r\n\r\n".$corps);
            fclose($connexion);
        }
    } finally {
        $duree = microtime(true) - $debut;

        foreach ($muettes as $connexion) {
            fclose($connexion);
        }

        if ($ecoute !== null) {
            fclose($ecoute);
        }
    }

    return ['code' => $processus->getExitCode(), 'sortie' => $processus->getOutput(), 'duree' => $duree, 'ordres' => $ordres];
}

/**
 * La session que le faux pilote ouvre.
 */
function chromeChaudSessionDuFauxPilote(): string
{
    return '0123456789abcdef0123456789abcdef';
}

/**
 * Le faux pilote : il répond à chaque ordre comme ChromeDriver, sauf à ceux
 * dont la méthode et le chemin (`{id}` pour la session) sont dans `$pannes`.
 * Il laisse un ordre « muet » sans réponse (et, derrière lui, les ordres
 * suivants de la même session), et refuse un ordre « refusé » par
 * l'erreur que ChromeDriver rend quand il coupe un lancement de Chrome
 * (l'ouverture d'une session) ou une page (tout autre ordre), à chaque fois
 * ou la première seulement (« refusé une fois »).
 *
 * @param  array<mixed>  $pannes  « muet », « refusé » ou « refusé une fois »
 * @return Closure(string, string, int): ?array{0: int, 1: string}
 */
function chromeChaudFauxPilote(array $pannes = []): Closure
{
    return static function (string $methode, string $chemin, int $rang) use ($pannes): ?array {
        $ordre = str_replace(chromeChaudSessionDuFauxPilote(), '{id}', "{$methode} {$chemin}");
        $panne = $pannes[$ordre] ?? null;

        if ($panne === 'muet') {
            return null;
        }

        if ($panne === 'refusé' || ($panne === 'refusé une fois' && $rang === 1)) {
            return [500, $ordre === 'POST /session'
                ? '{"value":{"error":"session not created","message":"session not created: DevToolsActivePort file doesn\'t exist","stacktrace":""}}'
                : '{"value":{"error":"timeout","message":"timeout: Timed out receiving message from renderer: 60.000","stacktrace":""}}'];
        }

        return [200, match ($ordre) {
            'GET /status' => '{"value":{"message":"ChromeDriver ready for new sessions.","ready":true}}',
            'POST /session' => '{"value":{"capabilities":{"browserName":"chrome"},"sessionId":"'.chromeChaudSessionDuFauxPilote().'"}}',
            'GET /session/{id}/screenshot' => '{"value":"iVBORw0KGgo="}',
            default => '{"value":null}',
        }];
    };
}

/**
 * Les ordres reçus, réduits à leur méthode et leur chemin (`{id}` pour la
 * session), un ordre répété d'affilée écrit une fois avec son nombre.
 *
 * @param  list<array{methode: string, chemin: string, corps: mixed}>  $ordres
 * @return list<string>
 */
function chromeChaudOrdresEnClair(array $ordres): array
{
    $enClair = [];
    $precedent = null;
    $fois = 0;

    foreach ($ordres as $ordre) {
        $courant = str_replace(chromeChaudSessionDuFauxPilote(), '{id}', "{$ordre['methode']} {$ordre['chemin']}");

        if ($courant === $precedent) {
            $fois++;
            $enClair[array_key_last($enClair)] = "{$courant} ×{$fois}";

            continue;
        }

        $enClair[] = $courant;
        $precedent = $courant;
        $fois = 1;
    }

    return $enClair;
}

/**
 * Une ligne attendue de la chauffe, durées écrites « X s » et nombres
 * d'essais « N essais », en expression régulière.
 */
function chromeChaudLigneAttendue(string $modele): string
{
    return '/^'.str_replace(['X s', 'N essais'], ['\d+,\d s', '\d+ essais'], preg_quote($modele, '/')).'$/u';
}

it('chauffe Chrome par le pilote des parcours avant chaque lancement des parcours, sans faire tomber le job', function (): void {
    $jobs = chromeChaudJobsDesParcours();

    // Le job browser-shard lance les parcours : une garde qui ne lirait rien passerait sans rien prouver.
    expect(array_column($jobs, 'ou'))->toContain('ci.yml > browser-shard');

    foreach ($jobs as ['ou' => $ou, 'etapes' => $etapes]) {
        expect(chromeChaudDefautsDuJob($ou, $etapes))->toBe([]);
    }
});

it('reconnaît le démarrage du pilote sous toutes ses formes, et refuse une chauffe mal placée ou mal réglée', function (array $variante, ?string $defaut): void {
    $jobs = array_values(array_filter(chromeChaudJobsDesParcours(), static fn (array $job): bool => $job['ou'] === 'ci.yml > browser-shard'));

    expect($jobs)->toHaveCount(1);

    $defauts = chromeChaudDefautsDuJob($jobs[0]['ou'], chromeChaudVarianteDuJob($jobs[0]['etapes'], $variante));

    if ($defaut === null) {
        expect($defauts)->toBe([]);

        return;
    }

    expect($defauts)->toHaveCount(1)
        ->and($defauts[0] ?? '')->toContain($defaut);
})->with([
    'le démarrage sur deux lignes' => [['demarrage' => "./vendor/laravel/dusk/bin/chromedriver-linux \\\n    --port=9515 --log-path=storage/logs/chromedriver.log --readable-timestamp &\n"], null],
    'le démarrage sans --port=, sur le port par défaut' => [['demarrage' => "./vendor/laravel/dusk/bin/chromedriver-linux --log-path=storage/logs/chromedriver.log --readable-timestamp &\n"], null],
    'la chauffe avant un démarrage sur deux lignes' => [['demarrage' => "./vendor/laravel/dusk/bin/chromedriver-linux \\\n    --port=9515 --log-path=storage/logs/chromedriver.log --readable-timestamp &\n", 'chauffeAvant' => 'Start Chrome Driver'], 'la chauffe part avant le démarrage du pilote'],
    'la chauffe avant un démarrage sans --port=' => [['demarrage' => "chromedriver --log-path=storage/logs/chromedriver.log &\n", 'chauffeAvant' => 'Start Chrome Driver'], 'la chauffe part avant le démarrage du pilote'],
    'un démarrage sur deux lignes vers un autre port' => [['demarrage' => "./vendor/laravel/dusk/bin/chromedriver-linux \\\n    --port=9516 --log-path=storage/logs/chromedriver.log &\n"], 'doivent viser le port du pilote que le job démarre'],
    'un démarrage sans --port= quand les parcours visent un autre port' => [['demarrage' => "chromedriver --log-path=storage/logs/chromedriver.log &\n", 'port' => 9516], 'doivent viser le port du pilote que le job démarre'],
    'un --port sans valeur, qui arrête ChromeDriver' => [['demarrage' => "chromedriver --port 9515 &\n"], 'doivent viser le port du pilote que le job démarre'],
    'un port écrit par une variable, qui ne se compare à rien' => [['demarrage' => "chromedriver --port=\"\$PORT_DU_PILOTE\" &\n"], 'doivent viser le port du pilote que le job démarre'],
    'un démarrage en commentaire seulement' => [['demarrage' => "# ./vendor/laravel/dusk/bin/chromedriver-linux --port=9515 &\ntail storage/logs/chromedriver.log\n"], 'aucune étape ne démarre ChromeDriver'],
    'aucune étape de démarrage' => [['demarrage' => false], 'aucune étape ne démarre ChromeDriver'],
    'un démarrage sous condition' => [['demarrageSi' => 'matrix.shard == 1'], 'le démarrage du pilote ne doit dépendre d\'aucune condition'],
    'la chauffe après les parcours' => [['chauffeAvant' => 'Dump Logs on Failure'], 'la chauffe vient après les parcours'],
    'la chauffe sur un seul éclat' => [['chauffe' => ['if' => 'matrix.shard == 1']], 'aucune condition'],
    'la chauffe qui fait tomber le job' => [['chauffe' => ['continue-on-error' => false]], 'ne doit pas faire tomber le job'],
    'la chauffe sur un autre pilote que les parcours' => [['chauffe' => ['env' => ['PILOTE' => 'http://127.0.0.1:4444']]], 'doit passer par le pilote des parcours'],
    'deux essais pour huit minutes' => [['chauffe' => ['env' => ['ESSAIS_PAR_ORDRE' => 2]]], 'doivent couvrir SECONDES_DE_CHAUFFE'],
    'une borne d\'étape sous celle de la chauffe' => [['chauffe' => ['timeout-minutes' => 8]], 'doit laisser la chauffe finir sur la sienne'],
]);

it('ouvre une session avec les options sans tête et la borne d\'une page des parcours, rend une page http, en prend la capture, la ferme et écrit ses durées', function (): void {
    $optionsSansTete = chromeChaudOptionsSansTeteDesParcours();

    // Sans tête, les parcours désactivent au moins le bac à sable, le GPU et /dev/shm : la comparaison porte sur quelque chose.
    expect($optionsSansTete)->toContain('--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage');

    foreach (chromeChaudJobsDesParcours() as ['ou' => $ou, 'etapes' => $etapes]) {
        $rejeu = chromeChaudRejouerLaChauffe($etapes[chromeChaudRangDeLaChauffe($etapes, $ou)], 20, chromeChaudFauxPilote());
        $ouverture = $rejeu['ordres'][1]['corps'] ?? null;
        $capacites = data_get($ouverture, 'capabilities.alwaysMatch') ?? data_get($ouverture, 'capabilities.firstMatch.0');
        $options = data_get($capacites, 'goog:chromeOptions');
        $visite = data_get($rejeu['ordres'][2]['corps'] ?? null, 'url');

        expect($rejeu['code'])->toBe(0, "{$ou} : {$rejeu['sortie']}")
            ->and(chromeChaudOrdresEnClair($rejeu['ordres']))->toBe([
                'GET /status',
                'POST /session',
                'POST /session/{id}/url',
                'GET /session/{id}/screenshot',
                'DELETE /session/{id}',
            ])
            ->and(data_get($options, 'args'))->toBeArray()->toContain(...$optionsSansTete)
            // Le Chrome de la chauffe est celui que le pilote trouve, comme pour les parcours.
            ->and($options)->not->toHaveKey('binary')
            // Une page muette tombe au bout du même temps que dans les parcours, et se rejoue dans le même Chrome.
            ->and(data_get($capacites, 'timeouts.pageLoad'))->toBe(chromeChaudBorneDUnePageDesParcours())
            // La première visite des parcours, restée muette le 12/10, est une page http sur 127.0.0.1.
            ->and($visite)->toBeString()->toStartWith('http://127.0.0.1:')
            ->and(trim($rejeu['sortie']))->toMatch(chromeChaudLigneAttendue('Chrome chaud en X s : pilote prêt en X s, session ouverte en X s, page rendue en X s, capture en X s, session fermée en X s.'));
    }
});

it('rejoue un ordre que le pilote refuse ou laisse sans réponse, rend la main sur ses bornes et le signale', function (?array $pannes, int $secondes, array $variables, int $code, array $ordres, string $sortie, float $dureeMin, float $dureeMax): void {
    foreach (chromeChaudJobsDesParcours() as ['ou' => $ou, 'etapes' => $etapes]) {
        $rejeu = chromeChaudRejouerLaChauffe($etapes[chromeChaudRangDeLaChauffe($etapes, $ou)], $secondes, $pannes === null ? null : chromeChaudFauxPilote($pannes), $variables);
        $enClair = chromeChaudOrdresEnClair($rejeu['ordres']);
        $ordresConformes = count($enClair) === count($ordres)
            && array_filter(array_map(static fn (mixed $attendu, string $recu): bool => is_string($attendu) && fnmatch($attendu, $recu), $ordres, $enClair), static fn (bool $conforme): bool => ! $conforme) === [];
        preg_match_all('/×(\d+)/u', implode(', ', $enClair), $repetitions);
        preg_match_all('/ et (\d+) essais/u', $rejeu['sortie'], $essais);

        expect($rejeu['code'])->toBe($code, "{$ou} : {$rejeu['sortie']}")
            ->and($ordresConformes)->toBeTrue("{$ou} : ordres reçus ".implode(', ', $enClair))
            // Chaque ordre rejoué l'est autant de fois que la ligne de la chauffe le dit.
            ->and($essais[1])->toBe($repetitions[1], "{$ou} : {$rejeu['sortie']}")
            ->and($rejeu['duree'])->toBeGreaterThan($dureeMin)->toBeLessThan($dureeMax)
            ->and(trim($rejeu['sortie']))->toMatch(chromeChaudLigneAttendue($sortie));
    }
})->with([
    'chaque ordre refusé une fois, puis accepté' => [
        ['POST /session' => 'refusé une fois', 'POST /session/{id}/url' => 'refusé une fois', 'GET /session/{id}/screenshot' => 'refusé une fois'], 20, [], 0,
        ['GET /status', 'POST /session ×2', 'POST /session/{id}/url ×2', 'GET /session/{id}/screenshot ×2', 'DELETE /session/{id}'],
        'Chrome chaud en X s : pilote prêt en X s, session ouverte en X s et N essais, page rendue en X s et N essais, capture en X s et N essais, session fermée en X s.',
        2.5, 8.0,
    ],
    // Un refus immédiat et répété, comme un Chrome introuvable, ne coûte pas toute la borne : deux essais ici, sur trente secondes.
    'la session refusée à chaque essai' => [
        ['POST /session' => 'refusé'], 30, ['ESSAIS_PAR_ORDRE' => '2'], 1,
        ['GET /status', 'POST /session ×2'],
        '::warning title=Chauffe de Chrome::la session ne s\'est pas ouverte après X s (pilote prêt en X s, attente vaine en X s et N essais). Le premier parcours fera son propre diagnostic (#2046).',
        0.9, 6.0,
    ],
    'la session muette' => [
        ['POST /session' => 'muet'], 1, [], 1,
        ['GET /status', 'POST /session'],
        '::warning title=Chauffe de Chrome::la session ne s\'est pas ouverte après X s (pilote prêt en X s, attente vaine en X s). Le premier parcours fera son propre diagnostic (#2046).',
        0.9, 6.0,
    ],
    // Deux secondes : le temps d'au moins deux essais, une seconde d'écart entre eux.
    'la page refusée à chaque essai' => [
        ['POST /session/{id}/url' => 'refusé'], 2, [], 1,
        ['GET /status', 'POST /session', 'POST /session/{id}/url ×*', 'DELETE /session/{id}'],
        '::warning title=Chauffe de Chrome::la page ne s\'est pas rendue après X s (pilote prêt en X s, session ouverte en X s, attente vaine en X s et N essais, session fermée en X s). Le premier parcours fera son propre diagnostic (#2046).',
        1.9, 7.0,
    ],
    // La fermeture attend derrière la page restée sans réponse, et renonce au bout de ses cinq secondes.
    'la page muette' => [
        ['POST /session/{id}/url' => 'muet'], 1, [], 1,
        ['GET /status', 'POST /session', 'POST /session/{id}/url', 'DELETE /session/{id}'],
        '::warning title=Chauffe de Chrome::la page ne s\'est pas rendue après X s (pilote prêt en X s, session ouverte en X s, attente vaine en X s, fermeture sans réponse en X s). Le premier parcours fera son propre diagnostic (#2046).',
        5.9, 10.0,
    ],
    'la capture muette' => [
        ['GET /session/{id}/screenshot' => 'muet'], 1, [], 1,
        ['GET /status', 'POST /session', 'POST /session/{id}/url', 'GET /session/{id}/screenshot', 'DELETE /session/{id}'],
        '::warning title=Chauffe de Chrome::la capture n\'est pas venue après X s (pilote prêt en X s, session ouverte en X s, page rendue en X s, attente vaine en X s, fermeture sans réponse en X s). Le premier parcours fera son propre diagnostic (#2046).',
        5.9, 10.0,
    ],
    'la fermeture refusée' => [
        ['DELETE /session/{id}' => 'refusé'], 20, [], 0,
        ['GET /status', 'POST /session', 'POST /session/{id}/url', 'GET /session/{id}/screenshot', 'DELETE /session/{id}'],
        'Chrome chaud en X s : pilote prêt en X s, session ouverte en X s, page rendue en X s, capture en X s, fermeture refusée en X s.',
        0.0, 6.0,
    ],
    // Chaque attente du statut renonce au bout de deux secondes.
    'le pilote muet' => [
        ['GET /status' => 'muet'], 1, [], 1,
        ['GET /status'],
        '::warning title=Chauffe de Chrome::le pilote ne répond pas après X s (attente vaine en X s). Le premier parcours fera son propre diagnostic (#2046).',
        1.9, 6.0,
    ],
    'le pilote absent' => [
        null, 1, [], 1,
        [],
        '::warning title=Chauffe de Chrome::le pilote ne répond pas après X s (attente vaine en X s). Le premier parcours fera son propre diagnostic (#2046).',
        0.9, 6.0,
    ],
]);
