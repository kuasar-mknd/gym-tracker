<?php

declare(strict_types=1);

/*
 * L'image de base suit la dernière 8.5 publiée (`1-php8.5`), mais ce qu'elle
 * donne dépend du jour où son éditeur l'a reconstruite. Une version de PHP qui
 * corrige une faille ne doit donc pas reposer sur ce jour : le Dockerfile pose
 * un plancher, que la construction vérifie avant tout le reste et sous lequel
 * elle s'arrête (#PR). 8.5.12 corrige CVE-2026-109618 et CVE-2026-109619.
 */

/** Le plus ancien PHP que l'image accepte de livrer. */
const PLANCHER_DE_PHP = '8.5.12';

/**
 * Les instructions de l'étape `base` du Dockerfile, de son FROM au suivant.
 *
 * @return list<string>
 */
function instructionsDeLEtapeDeBase(): array
{
    $texte = (string) file_get_contents(base_path('Dockerfile'));
    $instructions = preg_split('/(?<!\\\\)\n(?!\s)/', $texte);

    if ($instructions === false) {
        return [];
    }

    $etape = [];
    $dedans = false;

    foreach ($instructions as $instruction) {
        $instruction = trim($instruction);

        if ($instruction === '' || str_starts_with($instruction, '#')) {
            continue;
        }

        if (str_starts_with($instruction, 'FROM ')) {
            if ($dedans) {
                break;
            }

            $dedans = str_ends_with($instruction, ' AS base');
        }

        if ($dedans) {
            $etape[] = $instruction;
        }
    }

    return $etape;
}

it('suit la dernière 8.5 de l’image de base, sans figer une version ancienne', function (): void {
    $etape = instructionsDeLEtapeDeBase();

    expect($etape)->not->toBeEmpty('Le Dockerfile n’a plus d’étape `base`.')
        ->and($etape[0])->toBe('FROM dunglas/frankenphp:1-php8.5 AS base');
});

it('vérifie le plancher de PHP avant toute autre instruction de l’étape de base', function (): void {
    $etape = instructionsDeLEtapeDeBase();

    expect($etape)->toHaveKey(1);

    $verification = $etape[1];

    expect($verification)->toStartWith('RUN php -r ')
        ->toContain('version_compare(PHP_VERSION, ')
        // Sous le plancher, la construction s'arrête.
        ->toContain('|| {')
        ->toContain('exit 1; }');

    preg_match('/version_compare\(PHP_VERSION, "([0-9]+\.[0-9]+\.[0-9]+)", ">="\)/', $verification, $plancher);

    // PHP_VERSION se compare, par >=, à une version écrite en clair.
    expect($plancher)->toHaveKey(1);
    assert(isset($plancher[1]));

    expect(version_compare($plancher[1], PLANCHER_DE_PHP, '>='))->toBeTrue(
        "Le plancher {$plancher[1]} du Dockerfile est sous ".PLANCHER_DE_PHP.', qui corrige CVE-2026-109618 et CVE-2026-109619.',
    );
});
