<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Ce que l'audit d'infrastructure a fermé : les images de base du Dockerfile
 * portent leur digest (Dependabot `docker` le monte avec l'étiquette), les
 * images de Sail n'ont ni `latest` ni étiquette absente, la composition de
 * production transmet les anciennes clés, aucun fichier suivi par les clients
 * MCP ne porte un chemin de poste, et la CI ne publie l'image qu'après ses
 * contrôles, ne pousse `latest` que par la règle de metadata-action et lance
 * aussi le digest arm64, que `build` publie.
 */

it('épingle chaque image externe du Dockerfile par étiquette et digest', function (): void {
    preg_match_all('/^FROM\s+(?:--platform=\S+\s+)?(\S+)(?:\s+AS\s+(\S+))?/mi', (string) file_get_contents(base_path('Dockerfile')), $etapes, PREG_SET_ORDER);

    $internes = array_map(static fn (array $e): string => $e[2] ?? '', $etapes);
    $externes = array_filter($etapes, static fn (array $e): bool => ! in_array($e[1], $internes, true));

    expect($externes)->not->toBeEmpty()
        ->and(array_filter($externes, static fn (array $e): bool => preg_match('/^[\w.\/-]+:[\w.-]+@sha256:[0-9a-f]{64}$/', $e[1]) !== 1))->toBe([]);
});

it('ne lance aucune image de Sail sans étiquette ni sur latest', function (): void {
    $services = (array) data_get(Yaml::parseFile(base_path('compose.yaml')), 'services');
    $flottantes = [];

    foreach ($services as $nom => $service) {
        $image = is_array($service) ? ($service['image'] ?? null) : null;

        if (! is_string($image) || str_starts_with($image, 'sail-')) {
            continue;
        }

        $etiquette = str_contains($image, ':') ? substr($image, (int) strrpos($image, ':') + 1) : 'latest';

        if ($etiquette === 'latest') {
            $flottantes[] = "{$nom}: {$image}";
        }
    }

    expect($flottantes)->toBe([]);
});

it('transmet APP_PREVIOUS_KEYS à app, worker et scheduler', function (): void {
    $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));

    foreach (['app', 'worker', 'scheduler'] as $service) {
        expect(data_get($composition, "services.{$service}.environment.APP_PREVIOUS_KEYS"))->toBe('${APP_PREVIOUS_KEYS:-}', $service);
    }
});

it('ne suit aucun chemin de poste dans la configuration des clients MCP', function (string $fichier): void {
    expect((string) file_get_contents(base_path($fichier)))->not->toMatch('#/(Users|home)/[^/"]+/#')
        ->and((string) file_get_contents(base_path($fichier)))->not->toContain('SITE_PATH');
})->with(['.mcp.json', '.vscode/mcp.json']);

it('fait attendre à build les contrôles de sécurité et des workflows', function (): void {
    $needs = (array) data_get(Yaml::parseFile(base_path('.github/workflows/ci.yml')), 'jobs.build.needs');

    expect($needs)->toContain('semgrep', 'secrets', 'workflows');
});

it('ne pousse latest que par la règle enable de metadata-action, ni en dur ni par son défaut latest=auto', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($ci)->not->toMatch('/--tag\s+.*:latest\b/')
        ->and($ci)->toContain('flavor: latest=false')
        ->and($ci)->toContain("type=raw,value=latest,enable=\${{ github.ref == format('refs/heads/{0}', github.event.repository.default_branch) }}");
});

it('lance l’image démarrée aussi sur arm64, l’autre architecture publiée', function (): void {
    $demarrage = (array) data_get(Yaml::parseFile(base_path('.github/workflows/ci.yml')), 'jobs.demarrage');

    expect(data_get($demarrage, 'strategy.matrix.arch'))->toBe(['amd64', 'arm64'])
        ->and($demarrage['runs-on'])->toContain('ubuntu-24.04-arm')
        ->and((string) json_encode($demarrage['steps']))->toContain('digests-${{ matrix.arch }}');
});

it('ferme les privilèges de chaque service de production (no-new-privileges sauf app, dont le port 80 tient à la capacité du binaire) et ne rend que les capacités éprouvées', function (): void {
    $services = (array) data_get(Yaml::parseFile(base_path('docker-compose.prod.yml')), 'services');
    $permises = [
        'app' => ['NET_BIND_SERVICE'],
        'db' => ['CHOWN', 'DAC_OVERRIDE', 'SETGID', 'SETUID'],
        'redis' => ['CHOWN', 'SETGID', 'SETUID'],
        'redis-cache' => ['CHOWN', 'SETGID', 'SETUID'],
        'redis-sessions' => ['CHOWN', 'SETGID', 'SETUID'],
        'worker' => [],
        'scheduler' => [],
    ];

    expect(array_keys($services))->toEqualCanonicalizing(array_keys($permises));

    foreach ($services as $nom => $service) {
        $service = (array) $service;

        expect(in_array('no-new-privileges:true', (array) ($service['security_opt'] ?? []), true))->toBe($nom !== 'app', $nom)
            ->and($service['cap_drop'] ?? [])->toBe(['ALL'], $nom)
            ->and($service['cap_add'] ?? [])->toEqualCanonicalizing($permises[$nom], $nom);
    }
});
