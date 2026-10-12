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
 * jamais quand il rend null. Sans faux pilote, la chauffe vise le port 1, où
 * rien n'écoute. Les chaînes de `$variables` prennent le pas sur
 * l'environnement de l'étape.
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
            $cle = "{$ordre['methode']} {$ordre['chemin']}";
            $recus[$cle] = ($recus[$cle] ?? 0) + 1;
            $reponse = $repondre($ordre['methode'], $ordre['chemin'], $recus[$cle]);

            if ($reponse === null) {
                $muettes[] = $connexion;

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
 * Il laisse un ordre « muet » sans réponse, et refuse un ordre « refusé » par
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
    $secondesParPage = intdiv(chromeChaudBorneDUnePageDesParcours(), 1000);

    // Le job browser-shard lance les parcours : une garde qui ne lirait rien passerait sans rien prouver.
    expect(array_column($jobs, 'ou'))->toContain('ci.yml > browser-shard');

    foreach ($jobs as ['ou' => $ou, 'etapes' => $etapes]) {
        $rangDeLaChauffe = chromeChaudRangDeLaChauffe($etapes, $ou);
        $chauffe = $etapes[$rangDeLaChauffe];
        $rangDesParcours = (int) chromeChaudRangDe($etapes, chromeChaudLanceLesParcours(...));
        $rangDuPilote = chromeChaudRangDe($etapes, static fn (array $etape): bool => is_string($etape['run'] ?? null) && preg_match('/chromedriver\S*\s[^\n]*--port=\d+/', $etape['run']) === 1);
        $parcours = $etapes[$rangDesParcours];
        $script = is_string($parcours['run'] ?? null) ? $parcours['run'] : '';
        $pilote = data_get($parcours, 'env.DUSK_DRIVER_URL') ?? (preg_match('/^\s*DUSK_DRIVER_URL=(\S+)/m', $script, $trouve) === 1 ? $trouve[1] : null);
        $secondes = data_get($chauffe, 'env.SECONDES_DE_CHAUFFE');
        $essais = data_get($chauffe, 'env.ESSAIS_PAR_ORDRE');
        $minutes = data_get($chauffe, 'timeout-minutes');

        expect($rangDeLaChauffe)->toBeLessThan($rangDesParcours, "{$ou} : la chauffe vient après les parcours")
            // Une condition (`if:`) la sauterait sur certains éclats, ou sur tous.
            ->and(array_key_exists('if', $chauffe))->toBeFalse("{$ou} : la chauffe ne doit dépendre d'aucune condition, chaque éclat la paie")
            ->and(data_get($chauffe, 'continue-on-error'))->toBeTrue("{$ou} : une chauffe qui échoue ne doit pas faire tomber le job, le premier parcours fera son propre diagnostic")
            ->and(data_get($chauffe, 'env.PILOTE'))->toBe($pilote, "{$ou} : la chauffe doit passer par le pilote des parcours (DUSK_DRIVER_URL)")
            ->and($secondes)->toBeInt()->toBeGreaterThan(0)
            ->and($essais)->toBeInt()->toBeGreaterThan(1)
            ->and($minutes)->toBeInt();

        assert(is_int($secondes) && is_int($essais) && is_int($minutes) && is_string($pilote));

        // Le pilote coupe chaque lancement de Chrome et chaque page à soixante secondes : sans assez d'essais, la borne ne serait jamais atteinte.
        expect($essais * $secondesParPage)->toBeGreaterThanOrEqual($secondes, "{$ou} : ESSAIS_PAR_ORDRE essais d'une page des parcours doivent couvrir SECONDES_DE_CHAUFFE")
            // Sa propre borne, plus la fermeture (cinq secondes) et une seconde par ordre, tient sous celle de GitHub.
            ->and($minutes * 60)->toBeGreaterThan($secondes + 30, "{$ou} : la borne de l'étape doit laisser la chauffe finir sur la sienne et écrire ses durées");

        if ($rangDuPilote !== null) {
            $demarrage = $etapes[$rangDuPilote]['run'] ?? '';

            expect($rangDuPilote)->toBeLessThan($rangDeLaChauffe, "{$ou} : la chauffe part avant le démarrage du pilote")
                ->and(is_string($demarrage) && str_contains($demarrage, '--port='.parse_url($pilote, PHP_URL_PORT)))
                ->toBeTrue("{$ou} : la chauffe et les parcours doivent viser le pilote que le job démarre");
        }
    }
});

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
    'la page muette' => [
        ['POST /session/{id}/url' => 'muet'], 1, [], 1,
        ['GET /status', 'POST /session', 'POST /session/{id}/url', 'DELETE /session/{id}'],
        '::warning title=Chauffe de Chrome::la page ne s\'est pas rendue après X s (pilote prêt en X s, session ouverte en X s, attente vaine en X s, session fermée en X s). Le premier parcours fera son propre diagnostic (#2046).',
        0.9, 6.0,
    ],
    'la capture muette' => [
        ['GET /session/{id}/screenshot' => 'muet'], 1, [], 1,
        ['GET /status', 'POST /session', 'POST /session/{id}/url', 'GET /session/{id}/screenshot', 'DELETE /session/{id}'],
        '::warning title=Chauffe de Chrome::la capture n\'est pas venue après X s (pilote prêt en X s, session ouverte en X s, page rendue en X s, attente vaine en X s, session fermée en X s). Le premier parcours fera son propre diagnostic (#2046).',
        0.9, 6.0,
    ],
    'le pilote absent' => [
        null, 1, [], 1,
        [],
        '::warning title=Chauffe de Chrome::le pilote ne répond pas après X s (attente vaine en X s). Le premier parcours fera son propre diagnostic (#2046).',
        0.9, 6.0,
    ],
]);
