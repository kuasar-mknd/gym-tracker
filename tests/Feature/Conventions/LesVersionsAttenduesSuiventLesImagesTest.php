<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * « Versions des services » compare la version que rend chaque serveur à
 * celle que la composition de production attend (#2152) : les deux variables
 * suivent l'étiquette `image:` de leur service, sans quoi le contrôle
 * passerait au rouge au premier déploiement, ou resterait vert sur une
 * version que la composition ne lance plus. Une montée de `db` ou de
 * `redis` change donc aussi sa variable, dans la même PR.
 */

/**
 * La version qu'une étiquette d'image désigne : son étiquette, sans la
 * variante de l'image (`-alpine`, `-bookworm`).
 */
function versionsAttendueDeLEtiquette(string $image): string
{
    $etiquette = str_contains($image, ':') ? substr($image, (int) strrpos($image, ':') + 1) : 'latest';

    return explode('-', $etiquette, 2)[0];
}

/**
 * @return array<mixed>
 */
function versionsAttenduesComposition(): array
{
    $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));

    return is_array($composition) ? $composition : [];
}

it('tire la version attendue de l’étiquette d’une image', function (string $image, string $attendue): void {
    expect(versionsAttendueDeLEtiquette($image))->toBe($attendue);
})->with([
    'une ligne de MySQL' => ['mysql:8.4', '8.4'],
    'une version exacte de MySQL' => ['mysql:9.7.4', '9.7.4'],
    'une version au calendrier' => ['mysql:26.10.0', '26.10.0'],
    'une version exacte de Redis, variante alpine' => ['redis:8.10.3-alpine', '8.10.3'],
    'une majeure de Redis, variante alpine' => ['redis:8-alpine', '8'],
    'une image qualifiée' => ['docker.io/library/redis:8.10.3-alpine', '8.10.3'],
]);

it('transmet à chaque conteneur de l’application la version de db et de redis que la composition lance', function (): void {
    $composition = versionsAttenduesComposition();
    $base = data_get($composition, 'services.db.image');
    $cache = data_get($composition, 'services.redis.image');

    expect($base)->toBeString()->toStartWith('mysql:')
        ->and($cache)->toBeString()->toStartWith('redis:');
    assert(is_string($base) && is_string($cache));

    $attendues = [
        'VERSION_ATTENDUE_DE_MYSQL' => versionsAttendueDeLEtiquette($base),
        'VERSION_ATTENDUE_DE_REDIS' => versionsAttendueDeLEtiquette($cache),
    ];

    foreach (['app', 'worker', 'scheduler'] as $service) {
        $environnement = data_get($composition, "services.{$service}.environment");

        expect($environnement)->toBeArray();
        assert(is_array($environnement));

        foreach ($attendues as $variable => $version) {
            expect($environnement[$variable] ?? null)->toBe($version, "{$service} doit recevoir {$variable} à {$version}, l'étiquette de son image");
        }
    }
});

it('lit les versions attendues dans les variables que la composition transmet', function (): void {
    $configuration = (string) file_get_contents(config_path('health.php'));

    expect($configuration)->toContain("'mysql' => env('VERSION_ATTENDUE_DE_MYSQL')")
        ->toContain("'redis' => env('VERSION_ATTENDUE_DE_REDIS')");
});
