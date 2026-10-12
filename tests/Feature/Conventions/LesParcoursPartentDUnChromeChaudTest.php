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
 * de DuskTestCase, une page http rendue, une capture, la fermeture. La chauffe
 * est bornée, écrit ses durées, et ne fait jamais tomber le job.
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
 * Les options que les parcours ne passent à Chrome que sans tête, comme en
 * CI : celles qui changent la façon dont il démarre et dont il rend.
 *
 * @return list<string>
 */
function chromeChaudOptionsSansTeteDesParcours(): array
{
    $arguments = static function (bool $sansTete): array {
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
        $options = $parcours->capacites()->getCapability(ChromeOptions::CAPABILITY);
        $arguments = $options instanceof ChromeOptions ? (((array) $options->toArray())['args'] ?? []) : [];

        return array_values(array_filter(is_array($arguments) ? $arguments : [], is_string(...)));
    };

    return array_values(array_diff($arguments(true), $arguments(false)));
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
 * pilote qui répond à chaque ordre ce que `$repondre` rend, ou jamais quand il
 * rend null. Sans faux pilote, la chauffe vise le port 1, où rien n'écoute.
 *
 * @param  array<mixed>  $etape
 * @param  (Closure(string, string): ?string)|null  $repondre
 * @return array{code: int|null, sortie: string, duree: float, ordres: list<array{methode: string, chemin: string, corps: mixed}>}
 */
function chromeChaudRejouerLaChauffe(array $etape, int $secondes, ?Closure $repondre): array
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
        timeout: 30,
    );
    $ordres = [];
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
            $reponse = $repondre($ordre['methode'], $ordre['chemin']);

            if ($reponse === null) {
                $muettes[] = $connexion;

                continue;
            }

            fwrite($connexion, "HTTP/1.1 200 OK\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: ".strlen($reponse)."\r\nConnection: close\r\n\r\n".$reponse);
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
 * Le faux pilote : il répond à chaque ordre comme ChromeDriver, sauf à ceux
 * dont la méthode et le chemin sont dans `$muets`.
 *
 * @param  list<string>  $muets
 * @return Closure(string, string): ?string
 */
function chromeChaudFauxPilote(array $muets = []): Closure
{
    return static function (string $methode, string $chemin) use ($muets): ?string {
        if (in_array("{$methode} {$chemin}", $muets, true)) {
            return null;
        }

        return match ("{$methode} {$chemin}") {
            'GET /status' => '{"value":{"message":"ChromeDriver ready for new sessions.","ready":true}}',
            'POST /session' => '{"value":{"capabilities":{"browserName":"chrome"},"sessionId":"0123456789abcdef0123456789abcdef"}}',
            'GET /session/0123456789abcdef0123456789abcdef/screenshot' => '{"value":"iVBORw0KGgo="}',
            default => '{"value":null}',
        };
    };
}

/**
 * Les ordres reçus, réduits à leur méthode et leur chemin.
 *
 * @param  list<array{methode: string, chemin: string, corps: mixed}>  $ordres
 * @return list<string>
 */
function chromeChaudOrdresEnClair(array $ordres): array
{
    return array_map(static fn (array $ordre): string => "{$ordre['methode']} {$ordre['chemin']}", $ordres);
}

it('chauffe Chrome par le pilote des parcours avant chaque lancement des parcours, sans faire tomber le job', function (): void {
    $jobs = chromeChaudJobsDesParcours();

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
        $minutes = data_get($chauffe, 'timeout-minutes');

        expect($rangDeLaChauffe)->toBeLessThan($rangDesParcours, "{$ou} : la chauffe vient après les parcours")
            ->and(data_get($chauffe, 'continue-on-error'))->toBeTrue("{$ou} : une chauffe qui échoue ne doit pas faire tomber le job, le premier parcours fera son propre diagnostic")
            ->and(data_get($chauffe, 'env.PILOTE'))->toBe($pilote, "{$ou} : la chauffe doit passer par le pilote des parcours (DUSK_DRIVER_URL)")
            ->and($secondes)->toBeInt()->toBeGreaterThan(0)
            ->and($minutes)->toBeInt();

        assert(is_int($secondes) && is_int($minutes) && is_string($pilote));

        // Sa propre borne, plus la fermeture (cinq secondes) et une seconde par ordre, tient sous celle de GitHub.
        expect($minutes * 60)->toBeGreaterThan($secondes + 30, "{$ou} : la borne de l'étape doit laisser la chauffe finir sur la sienne et écrire ses durées");

        if ($rangDuPilote !== null) {
            $demarrage = $etapes[$rangDuPilote]['run'] ?? '';

            expect($rangDuPilote)->toBeLessThan($rangDeLaChauffe, "{$ou} : la chauffe part avant le démarrage du pilote")
                ->and(is_string($demarrage) && str_contains($demarrage, '--port='.parse_url($pilote, PHP_URL_PORT)))
                ->toBeTrue("{$ou} : la chauffe et les parcours doivent viser le pilote que le job démarre");
        }
    }
});

it('ouvre une session avec les options sans tête des parcours, rend une page http, en prend la capture, la ferme et écrit ses durées', function (): void {
    $optionsSansTete = chromeChaudOptionsSansTeteDesParcours();

    // Sans tête, les parcours désactivent au moins le bac à sable, le GPU et /dev/shm : la comparaison porte sur quelque chose.
    expect($optionsSansTete)->toContain('--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage');

    foreach (chromeChaudJobsDesParcours() as ['ou' => $ou, 'etapes' => $etapes]) {
        $rejeu = chromeChaudRejouerLaChauffe($etapes[chromeChaudRangDeLaChauffe($etapes, $ou)], 20, chromeChaudFauxPilote());
        $session = '/session/0123456789abcdef0123456789abcdef';
        $ouverture = $rejeu['ordres'][1]['corps'] ?? null;
        $options = data_get($ouverture, 'capabilities.alwaysMatch.goog:chromeOptions') ?? data_get($ouverture, 'capabilities.firstMatch.0.goog:chromeOptions');
        $visite = data_get($rejeu['ordres'][2]['corps'] ?? null, 'url');

        expect($rejeu['code'])->toBe(0, "{$ou} : {$rejeu['sortie']}")
            ->and(chromeChaudOrdresEnClair($rejeu['ordres']))->toBe([
                'GET /status',
                'POST /session',
                "POST {$session}/url",
                "GET {$session}/screenshot",
                "DELETE {$session}",
            ])
            ->and(data_get($options, 'args'))->toBeArray()->toContain(...$optionsSansTete)
            // Le Chrome de la chauffe est celui que le pilote trouve, comme pour les parcours.
            ->and($options)->not->toHaveKey('binary')
            // La première visite des parcours, restée muette le 12/10, est une page http sur 127.0.0.1.
            ->and($visite)->toBeString()->toStartWith('http://127.0.0.1:')
            ->and(trim($rejeu['sortie']))->toMatch('/^Chrome chaud en \d+,\d s : pilote prêt en \d+,\d s, session ouverte en \d+,\d s, page rendue en \d+,\d s, capture en \d+,\d s, session fermée en \d+,\d s\.$/');
    }
});

it('rend la main sur sa borne quand le rendu ne répond pas, ferme sa session et le signale', function (): void {
    $session = '/session/0123456789abcdef0123456789abcdef';

    foreach (chromeChaudJobsDesParcours() as ['ou' => $ou, 'etapes' => $etapes]) {
        $rejeu = chromeChaudRejouerLaChauffe($etapes[chromeChaudRangDeLaChauffe($etapes, $ou)], 2, chromeChaudFauxPilote(["POST {$session}/url"]));

        expect($rejeu['code'])->toBe(1, "{$ou} : {$rejeu['sortie']}")
            ->and($rejeu['duree'])->toBeGreaterThan(1.5)->toBeLessThan(8.0)
            ->and(chromeChaudOrdresEnClair($rejeu['ordres']))->toBe(['GET /status', 'POST /session', "POST {$session}/url", "DELETE {$session}"])
            ->and(trim($rejeu['sortie']))->toMatch('/^::warning title=Chauffe de Chrome::la page ne s\'est pas rendue après \d+,\d s \(pilote prêt en \d+,\d s, session ouverte en \d+,\d s, session fermée en \d+,\d s\)\. Le premier parcours fera son propre diagnostic \(#2046\)\.$/');
    }
});

it('rend la main sur sa borne quand le pilote ne répond pas, et le signale', function (): void {
    foreach (chromeChaudJobsDesParcours() as ['ou' => $ou, 'etapes' => $etapes]) {
        $rejeu = chromeChaudRejouerLaChauffe($etapes[chromeChaudRangDeLaChauffe($etapes, $ou)], 2, null);

        expect($rejeu['code'])->toBe(1, "{$ou} : {$rejeu['sortie']}")
            ->and($rejeu['duree'])->toBeGreaterThan(1.5)->toBeLessThan(8.0)
            ->and(trim($rejeu['sortie']))->toMatch('/^::warning title=Chauffe de Chrome::le pilote ne répond pas après \d+,\d s\. Le premier parcours fera son propre diagnostic \(#2046\)\.$/');
    }
});
