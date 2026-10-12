<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use Symfony\Component\Yaml\Yaml;

/*
 * app, worker et scheduler écrivent chaque ligne de journal sur stderr
 * (`LOG_STACK: stderr,daily`), donc dans la copie que garde le moteur de
 * conteneurs et que lit `docker logs`. Aucun service n'y posait de rotation :
 * cette copie grandissait pendant toute la vie du conteneur, avec
 * l'identifiant du compte connecté dans chaque exception et, dans le message
 * d'une erreur SQL, ce qu'il avait saisi. Un compte supprimé y laissait des
 * traces sans borne (#1938).
 *
 * Décision du propriétaire du dépôt (2026-10-12) : le pilote `json-file`, que
 * lit `docker logs`, avec une rotation par taille, trois fichiers de 10 Mo,
 * soit environ 30 Mo au plus par conteneur. Les plus anciennes lignes partent
 * à mesure que d'autres s'écrivent : la borne est une taille, pas une durée.
 *
 * Le réglage vit dans la composition, service par service : il y prend le pas
 * sur le pilote de journaux posé pour tout le démon, que le dépôt ne voit pas
 * et qu'aucun test ne peut lire.
 */

/**
 * Le réglage `logging` exigé de chaque conteneur de l'application, clé par
 * clé (en notation pointée).
 *
 * @return array{driver: string, 'options.max-size': string, 'options.max-file': string}
 */
function journauxBornesReglageAttendu(): array
{
    return ['driver' => 'json-file', 'options.max-size' => '10m', 'options.max-file' => '3'];
}

/**
 * Les services d'une composition qui font tourner l'image de l'application,
 * ancres résolues. app, worker et scheduler doivent en être : sans eux, la
 * garde passerait aussi le jour où plus rien ne serait reconnu. Un nouveau
 * service de l'image en est aussi, sans qu'il faille l'ajouter ici : la garde
 * exige de lui le même réglage.
 *
 * @param  array<mixed>  $composition
 * @return array<string, array<string, mixed>>
 */
function journauxBornesServicesDeLApplication(array $composition): array
{
    $services = $composition['services'] ?? null;

    expect($services)->toBeArray();
    assert(is_array($services));

    $services = array_filter(
        $services,
        static fn (mixed $service): bool => is_array($service)
            && is_string($service['image'] ?? null)
            && str_starts_with($service['image'], 'ghcr.io/kuasar-mknd/gym-tracker'),
    );

    expect(array_keys($services))->toContain('app', 'worker', 'scheduler');

    /** @var array<string, array<string, mixed>> $services */
    return $services;
}

/**
 * Les écarts de chaque service de l'image d'une composition, précédés de son
 * nom. Une liste vide : tous portent la rotation par taille décidée.
 *
 * @param  array<mixed>  $composition
 * @return list<string>
 */
function journauxBornesFautes(array $composition): array
{
    $fautes = [];

    foreach (journauxBornesServicesDeLApplication($composition) as $nom => $service) {
        foreach (journauxBornesEcarts($service) as $ecart) {
            $fautes[] = "{$nom} : {$ecart}";
        }
    }

    return $fautes;
}

/**
 * Ce qui, dans le réglage `logging` d'un service, s'écarte de la rotation par
 * taille décidée. Une liste vide : le réglage est celui attendu.
 *
 * Les valeurs se comparent en chaînes, comme le moteur les reçoit : Compose
 * convertit chaque option, et `max-file: 3`, sans guillemets, vaut `'3'`.
 *
 * @param  array<mixed>  $service
 * @return list<string>
 */
function journauxBornesEcarts(array $service): array
{
    $reglage = $service['logging'] ?? null;

    if (! is_array($reglage)) {
        return ['aucun réglage `logging` : la copie de docker logs suit le réglage du démon, sans rotation par défaut'];
    }

    $ecarts = [];

    foreach (journauxBornesReglageAttendu() as $cle => $attendue) {
        $lue = data_get($reglage, $cle);
        $lue = is_int($lue) ? (string) $lue : $lue;

        if ($lue !== $attendue) {
            $ecarts[] = sprintf('%s vaut %s au lieu de %s', $cle, var_export($lue, true), var_export($attendue, true));
        }
    }

    return $ecarts;
}

it('borne par la taille les journaux de app, worker et scheduler', function (): void {
    $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));
    assert(is_array($composition));

    $fautes = journauxBornesFautes($composition);

    expect($fautes)->toBe([], sprintf(
        'La copie des journaux que garde docker logs doit tourner par taille, json-file, max-size 10m et max-file 3 '
        ."(#1938) : un compte supprimé y laisse sinon des traces sans borne.\n- %s",
        implode("\n- ", $fautes),
    ));
});

it('reconnaît une rotation par taille conforme et ce qui s’en écarte', function (array $service, array $attendus): void {
    expect(journauxBornesEcarts($service))->toBe($attendus);
})->with([
    'conforme' => [
        ['logging' => ['driver' => 'json-file', 'options' => ['max-size' => '10m', 'max-file' => '3']]],
        [],
    ],
    'aucun réglage' => [
        [],
        ['aucun réglage `logging` : la copie de docker logs suit le réglage du démon, sans rotation par défaut'],
    ],
    'json-file sans option' => [
        ['logging' => ['driver' => 'json-file']],
        ["options.max-size vaut NULL au lieu de '10m'", "options.max-file vaut NULL au lieu de '3'"],
    ],
    'un autre pilote' => [
        ['logging' => ['driver' => 'local', 'options' => ['max-size' => '10m', 'max-file' => '3']]],
        ["driver vaut 'local' au lieu de 'json-file'"],
    ],
    'une taille plus grande' => [
        ['logging' => ['driver' => 'json-file', 'options' => ['max-size' => '100m', 'max-file' => '3']]],
        ["options.max-size vaut '100m' au lieu de '10m'"],
    ],
    'un nombre de fichiers sans guillemets, que Compose convertit' => [
        ['logging' => ['driver' => 'json-file', 'options' => ['max-size' => '10m', 'max-file' => 3]]],
        [],
    ],
    'un fichier de plus' => [
        ['logging' => ['driver' => 'json-file', 'options' => ['max-size' => '10m', 'max-file' => 4]]],
        ["options.max-file vaut '4' au lieu de '3'"],
    ],
]);

it('lit le réglage partagé par une ancre comme celui de chaque service', function (): void {
    $composition = Yaml::parse(<<<'YAML'
        x-app-logging: &app-logging
            driver: json-file
            options:
                max-size: '10m'
                max-file: '3'
        services:
            app:
                logging: *app-logging
        YAML);

    expect(data_get($composition, 'services.app'))->toBeArray();

    /** @var array<mixed> $service */
    $service = data_get($composition, 'services.app');

    expect(journauxBornesEcarts($service))->toBe([]);
});

it('exige le réglage d’un nouveau service de l’image sans qu’il faille l’ajouter à la garde', function (): void {
    $composition = Yaml::parse(<<<'YAML'
        x-app-logging: &app-logging
            driver: json-file
            options:
                max-size: '10m'
                max-file: '3'
        services:
            app:
                image: ghcr.io/kuasar-mknd/gym-tracker:v1
                logging: *app-logging
            worker:
                image: ghcr.io/kuasar-mknd/gym-tracker:v1
                logging: *app-logging
            scheduler:
                image: ghcr.io/kuasar-mknd/gym-tracker:v1
                logging: *app-logging
            migrations:
                image: ghcr.io/kuasar-mknd/gym-tracker:v1
                logging: *app-logging
            oubli:
                image: ghcr.io/kuasar-mknd/gym-tracker:v1
            db:
                image: mysql:8.4
        YAML);
    assert(is_array($composition));

    expect(array_keys(journauxBornesServicesDeLApplication($composition)))->toBe(['app', 'worker', 'scheduler', 'migrations', 'oubli'])
        ->and(journauxBornesFautes($composition))->toBe([
            'oubli : aucun réglage `logging` : la copie de docker logs suit le réglage du démon, sans rotation par défaut',
        ]);
});

it('ne passe pas à vide quand app, worker ou scheduler ne fait plus tourner l’image', function (): void {
    $composition = ['services' => [
        'app' => ['image' => 'ghcr.io/kuasar-mknd/gym-tracker:v1'],
        'worker' => ['image' => 'ghcr.io/kuasar-mknd/gym-tracker:v1'],
        'scheduler' => ['image' => 'example.org/autre-image:v1'],
    ]];

    expect(static fn (): array => journauxBornesServicesDeLApplication($composition))
        ->toThrow(ExpectationFailedException::class);
});
