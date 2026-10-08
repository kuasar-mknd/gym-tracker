<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Un réseau `internal: true` n'a aucune route vers l'extérieur. Dans
 * `docker-compose.prod.yml`, worker et scheduler n'étaient reliés qu'à
 * backend, interne (#2019) : les notifications en file, que Horizon envoie
 * depuis worker (push des records, des succès et du rappel d'entraînement,
 * avis de changement d'adresse, avis des sauvegardes lancées du panneau, tout
 * courriel mis en file), et les alertes de santé, que scheduler envoie
 * lui-même, ne joignaient ni le relais SMTP ni les services push. Seul app,
 * relié aussi à frontend, pouvait envoyer.
 *
 * Rien d'autre ne pouvait le voir : la suite remplace les envois par des
 * faux, et un envoi qui ne part pas ne laisse, au mieux, qu'un job en échec
 * que personne ne regarde.
 *
 * D'où la règle, tenue par les deux bouts : chaque processus qui envoie (le
 * serveur web, Horizon ou un worker de file, le planificateur) a une sortie,
 * et aucun autre service, db et redis compris, n'en a.
 */

/**
 * Le fichier de composition de production, analysé.
 *
 * @return array{services: array<string, array<string, mixed>>, networks?: mixed}
 */
function envoisComposition(): array
{
    $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));

    expect($composition)->toBeArray()->toHaveKey('services');
    assert(is_array($composition));
    expect($composition['services'])->toBeArray();
    expect($composition['services'])->not->toBeEmpty();

    /** @var array{services: array<string, array<string, mixed>>, networks?: mixed} $composition */
    return $composition;
}

/**
 * Ce que lance un service : sa `command`, ou, sans elle, le CMD du Dockerfile
 * quand il fait tourner l'image de l'application.
 *
 * @param  array<mixed>  $service
 */
function envoisCommandeDuService(array $service): string
{
    $commande = $service['command'] ?? null;
    $image = $service['image'] ?? null;

    if ($commande === null && is_string($image) && str_starts_with($image, 'ghcr.io/kuasar-mknd/gym-tracker')) {
        preg_match('/^CMD\s+(\[.+\])\s*$/m', (string) file_get_contents(base_path('Dockerfile')), $cmd);
        $commande = json_decode($cmd[1] ?? '[]', true);
    }

    if (is_array($commande)) {
        return implode(' ', array_filter($commande, is_string(...)));
    }

    return is_string($commande) ? $commande : '';
}

/**
 * Dit si une commande lance un processus qui envoie : le serveur web
 * (Octane, `serve`), Horizon ou un worker de file, le planificateur.
 */
function envoisLanceUnExpediteur(string $commande): bool
{
    return preg_match('/\bartisan\s+(?:horizon|queue:work|schedule:work|schedule:run|octane:[\w-]+|serve)(?:\s|$)/', $commande) === 1;
}

/**
 * Les réseaux d'une composition et, pour chacun, s'il est interne. Compose
 * relie à `default` un service qui ne nomme aucun réseau ; il n'est interne
 * que si la composition le déclare tel.
 *
 * @param  array<string, mixed>  $composition
 * @return array<string, bool>
 */
function envoisReseauxInternes(array $composition): array
{
    $internes = ['default' => false];
    $reseaux = $composition['networks'] ?? [];

    foreach (is_array($reseaux) ? $reseaux : [] as $nom => $reglages) {
        $internes[(string) $nom] = is_array($reglages) && ($reglages['internal'] ?? false) === true;
    }

    return $internes;
}

/**
 * Dit si un service peut joindre l'extérieur : relié à au moins un réseau non
 * interne. Compose relie à `default` un service sans `networks` comme un
 * service à la liste vide (`[]` ou `{}`). Un réseau que la composition ne
 * déclare pas compte comme une sortie : rien ne dit qu'il est interne.
 *
 * `network_mode` remplace les réseaux : `none` n'a aucune sortie, `host` et
 * `bridge` en ont une, `service:<x>` partage les réseaux du service x, dont on
 * reprend la réponse. `container:<x>` partage ceux d'un conteneur que la
 * composition ne décrit pas : la garde refuse de deviner, et échoue.
 *
 * @param  array<mixed>  $service
 * @param  array<string, array<string, mixed>>  $services  tous les services, pour suivre `service:<x>`
 * @param  array<string, bool>  $internes
 * @param  list<string>  $suivis  les services déjà suivis par `service:<x>`, contre une boucle
 */
function envoisPeutSortir(array $service, array $services, array $internes, array $suivis = []): bool
{
    $mode = $service['network_mode'] ?? null;

    if (is_string($mode) && str_starts_with($mode, 'service:')) {
        $partage = substr($mode, strlen('service:'));

        if (! array_key_exists($partage, $services) || in_array($partage, $suivis, true)) {
            throw new RuntimeException(sprintf('network_mode « %s » : service inconnu ou boucle.', $mode));
        }

        return envoisPeutSortir($services[$partage], $services, $internes, [...$suivis, $partage]);
    }

    if (is_string($mode) && str_starts_with($mode, 'container:')) {
        throw new RuntimeException(sprintf(
            'network_mode « %s » : les réseaux d’un conteneur hors de la composition ne se lisent pas ici.',
            $mode,
        ));
    }

    if ($mode !== null) {
        return $mode !== 'none';
    }

    $reseaux = $service['networks'] ?? [];
    $noms = is_array($reseaux) && ! array_is_list($reseaux) ? array_keys($reseaux) : (array) $reseaux;

    return array_any($noms === [] ? ['default'] : $noms, static fn (mixed $nom): bool => ! is_string($nom) || ! ($internes[$nom] ?? false));
}

/**
 * Les services de la composition, séparés selon qu'ils envoient ou non.
 *
 * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
 */
function envoisServicesSelonLEnvoi(): array
{
    $expediteurs = [];
    $autres = [];

    foreach (envoisComposition()['services'] as $nom => $service) {
        if (envoisLanceUnExpediteur(envoisCommandeDuService($service))) {
            $expediteurs[$nom] = $service;
        } else {
            $autres[$nom] = $service;
        }
    }

    return [$expediteurs, $autres];
}

it('donne une sortie au serveur web, à Horizon et au planificateur', function (): void {
    [$expediteurs] = envoisServicesSelonLEnvoi();
    $composition = envoisComposition();
    $internes = envoisReseauxInternes($composition);

    // Sans eux, la garde passerait aussi le jour où plus rien ne serait reconnu.
    expect(array_keys($expediteurs))->toEqualCanonicalizing(['app', 'worker', 'scheduler']);

    $sansSortie = array_keys(array_filter(
        $expediteurs,
        static fn (array $service): bool => ! envoisPeutSortir($service, $composition['services'], $internes),
    ));

    expect($sansSortie)->toBe([], sprintf(
        "Ces services envoient courriels, notifications push ou alertes de santé, mais ne sont reliés qu'à des "
        ."réseaux internes : ni le relais SMTP ni les services push ne leur sont accessibles, et rien ne part.\n- %s",
        implode("\n- ", $sansSortie),
    ));
});

it('ne donne de sortie à aucun autre service, db et redis compris', function (): void {
    [, $autres] = envoisServicesSelonLEnvoi();
    $composition = envoisComposition();
    $internes = envoisReseauxInternes($composition);

    expect(array_keys($autres))->toContain('db', 'redis');

    $avecSortie = array_keys(array_filter(
        $autres,
        static fn (array $service): bool => envoisPeutSortir($service, $composition['services'], $internes),
    ));

    expect($avecSortie)->toBe([], sprintf(
        "Ces services n'envoient rien et n'ont rien à joindre hors de la pile, mais sont reliés à un réseau qui sort : "
        ."qu'ils restent sur des réseaux internes.\n- %s",
        implode("\n- ", $avecSortie),
    ));
});

it('reconnaît les processus qui envoient', function (array $service, bool $attendu): void {
    expect(envoisLanceUnExpediteur(envoisCommandeDuService($service)))->toBe($attendu);
})->with([
    'Horizon' => [['command' => 'php artisan horizon'], true],
    'le planificateur' => [['command' => 'php artisan schedule:work'], true],
    'un passage du planificateur' => [['command' => 'php artisan schedule:run --verbose'], true],
    'un worker de file, en liste' => [['command' => ['php', 'artisan', 'queue:work', '--tries=3']], true],
    'le serveur web, CMD de l’image' => [['image' => 'ghcr.io/kuasar-mknd/gym-tracker:v1'], true],
    'la sonde de Horizon' => [['command' => 'php artisan horizon:status'], false],
    'MySQL' => [['image' => 'mysql:8.4', 'command' => '--skip-log-bin'], false],
    'Redis' => [['image' => 'redis:8-alpine', 'command' => 'redis-server --requirepass secret'], false],
    'une autre image, sans commande' => [['image' => 'redis:8-alpine'], false],
]);

/**
 * Les réseaux et les services d'une composition d'essai : backend interne,
 * frontend et sortie non, redis sur backend seul, app sur frontend.
 *
 * @return array{0: array<string, array<string, mixed>>, 1: array<string, bool>}
 */
function envoisCompositionDEssai(): array
{
    return [
        ['redis' => ['networks' => ['backend']], 'app' => ['networks' => ['frontend', 'backend']]],
        envoisReseauxInternes(['networks' => ['frontend' => null, 'backend' => ['internal' => true], 'sortie' => null]]),
    ];
}

it('reconnaît un service qui peut joindre l’extérieur', function (array $service, bool $attendu): void {
    [$services, $internes] = envoisCompositionDEssai();

    expect(envoisPeutSortir($service, $services, $internes))->toBe($attendu);
})->with([
    'worker avant #2019' => [['networks' => ['backend']], false],
    'worker depuis #2019' => [['networks' => ['backend', 'sortie']], true],
    'app' => [['networks' => ['frontend', 'backend']], true],
    'aucun réseau nommé, donc default' => [[], true],
    'une liste vide ([] ou {}), donc default' => [['networks' => []], true],
    'syntaxe longue, réseau interne' => [['networks' => ['backend' => ['aliases' => ['base']]]], false],
    'syntaxe longue, avec une sortie' => [['networks' => ['backend' => null, 'frontend' => null]], true],
    'un réseau non déclaré' => [['networks' => ['inconnu']], true],
    'network_mode: host' => [['network_mode' => 'host'], true],
    'network_mode: bridge' => [['network_mode' => 'bridge'], true],
    'network_mode: none' => [['network_mode' => 'none'], false],
    'network_mode: service:redis, sur backend seul' => [['network_mode' => 'service:redis'], false],
    'network_mode: service:app, qui sort' => [['network_mode' => 'service:app'], true],
]);

it('refuse de deviner les réseaux qu’il ne peut pas lire', function (string $mode, string $message): void {
    [$services, $internes] = envoisCompositionDEssai();
    $services['boucle'] = ['network_mode' => 'service:boucle'];

    expect(static fn (): bool => envoisPeutSortir(['network_mode' => $mode], $services, $internes))
        ->toThrow(new RuntimeException($message));
})->with([
    'un conteneur hors de la composition' => ['container:abc', 'network_mode « container:abc » : les réseaux d’un conteneur hors de la composition ne se lisent pas ici.'],
    'un service inconnu' => ['service:inconnu', 'network_mode « service:inconnu » : service inconnu ou boucle.'],
    'une boucle' => ['service:boucle', 'network_mode « service:boucle » : service inconnu ou boucle.'],
]);

it('lit une liste vide de la composition comme default', function (): void {
    $composition = Yaml::parse("services:\n  db:\n    networks: {}\n  redis:\n    networks: []\n");
    assert(is_array($composition) && is_array($composition['services']));
    [, $internes] = envoisCompositionDEssai();

    /** @var array<string, array<string, mixed>> $services */
    $services = $composition['services'];

    expect(envoisPeutSortir($services['db'], $services, $internes))->toBeTrue()
        ->and(envoisPeutSortir($services['redis'], $services, $internes))->toBeTrue();
});

it('lit l’attribut internal des réseaux déclarés, default compris', function (): void {
    expect(envoisReseauxInternes(['networks' => ['frontend' => null, 'backend' => ['internal' => true], 'default' => ['internal' => true]]]))
        ->toBe(['default' => true, 'frontend' => false, 'backend' => true])
        ->and(envoisReseauxInternes([]))->toBe(['default' => false]);
});
