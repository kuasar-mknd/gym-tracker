<?php

declare(strict_types=1);

/*
 * La garantie hors-ligne doit rester fraiche, et etre verifiee plutot qu'affirmee.
 *
 * Le job `audit` de la CI lance `composer audit --ignore-unreachable` : une panne
 * de packagist ne bloque plus une livraison. Le commentaire qui accompagne ce
 * choix explique que la garantie n'en est pas affaiblie, parce qu'elle ne repose
 * pas sur ce service seul — `composer.lock` porte `roave/security-advisories`,
 * dont les conflits refusent un paquet vulnerable sans reseau.
 *
 * C'etait vrai et ce n'etait pas verifie. Or cette garantie a une date : elle ne
 * connait que les avis publies avant la version verrouillee. Mesure le 24/08, la
 * reference du lock datait du 07/08 — dix-sept jours — et la rafraichir a fait
 * passer les conflits de 1072 a 1076. Quatre avis n'etaient bloques par rien
 * hors ligne, au moment precis ou l'on venait de rendre le regard en ligne non
 * bloquant.
 *
 * La cause est mecanique : la contrainte est `dev-latest`, une version de
 * developpement que Dependabot ne propose pas. Le paquet vieillit donc en
 * silence, sans PR pour le rappeler, pendant que tous ses voisins sont suivis.
 * Ce test est le rappel qui manquait, et le workflow `avis-hors-ligne.yml` est
 * ce qui le remet a zero tout seul.
 *
 * Le plancher de conflits est la pour qu'un paquet reduit a une coquille ne
 * passe pas la porte : une date fraiche sur un ensemble vide protegerait autant
 * qu'un commentaire.
 */

use Illuminate\Support\Carbon;

/**
 * L'entree du verrou pour l'ensemble d'avis hors-ligne.
 *
 * @return array{time: string, conflict: array<string, string>}
 */
function avisHorsLigne(): array
{
    $chemin = base_path('composer.lock');

    expect($chemin)->toBeFile();

    /** @var array{packages?: list<array<string, mixed>>, 'packages-dev'?: list<array<string, mixed>>} $verrou */
    $verrou = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);

    foreach (['packages', 'packages-dev'] as $section) {
        foreach ($verrou[$section] ?? [] as $paquet) {
            if (($paquet['name'] ?? null) !== 'roave/security-advisories') {
                continue;
            }

            /** @var array{time: string, conflict: array<string, string>} $paquet */
            return $paquet;
        }
    }

    throw new RuntimeException(
        "`roave/security-advisories` a disparu de composer.lock.\n".
        "C'est la seule protection qui ne demande pas le reseau, et le job `audit` ".
        'de la CI compte dessus pour se permettre `--ignore-unreachable`. La '.
        'retirer laisse la securite des dependances entierement suspendue a la '.
        'disponibilite de packagist.'
    );
}

it('garde une protection hors-ligne assez recente pour valoir quelque chose', function (): void {
    $avis = avisHorsLigne();

    $publication = Carbon::parse($avis['time']);
    $age = $publication->diffInDays(Carbon::now());

    // Trente jours : assez large pour ne pas rougir sur un week-end prolonge,
    // assez court pour qu'un oubli se voie avant de compter en mois. Le
    // workflow hebdomadaire vise bien plus serre ; ce plafond n'est que le
    // filet sous lui.
    expect($age)->toBeLessThanOrEqual(30, sprintf(
        "L'ensemble d'avis hors-ligne date du %s, soit %d jours.\n".
        'Il ne connait aucun avis publie depuis, alors que `composer audit` est '.
        'non bloquant en cas de panne : les deux regards peuvent donc etre '.
        "aveugles en meme temps.\n\n".
        'Rafraichir : composer update roave/security-advisories',
        $publication->toDateString(),
        $age,
    ));
});

it('garde un ensemble d avis substantiel et pas une coquille', function (): void {
    $avis = avisHorsLigne();

    // Plancher pose sous le compte reel (1076 le 24/08) avec de la marge : le
    // nombre monte au fil des publications et ne redescend pas durablement.
    expect(count($avis['conflict']))->toBeGreaterThan(900, sprintf(
        "L'ensemble d'avis hors-ligne ne declare que %d conflits.\n".
        'Une date fraiche sur un ensemble vide ne protege de rien.',
        count($avis['conflict']),
    ));
});
