<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Le dépôt est public : il ne nomme pas l'infrastructure qui le fait tourner.
 *
 * La documentation, les commentaires et les tests citaient le matériel, le
 * système, les outils d'orchestration et le réseau privé de la production, et
 * jusqu'à une adresse de ce réseau. Pour quelqu'un qui cherche une cible,
 * chacun de ces noms dit quelles failles essayer. Décision du propriétaire du
 * dépôt (2026-10-02) : la production se décrit par ce qu'elle fait — un proxy
 * inverse, une pile de conteneurs, un disque lent — jamais par ce qu'elle est.
 *
 * Cette garde lit chaque fichier suivi et refuse les noms interdits. Elle ne
 * les contient pas elle-même : seules leurs empreintes SHA-256 y figurent,
 * calculées sur le mot en minuscules (ou sur deux mots séparés d'une espace).
 * Pour en ajouter un : php -r 'echo hash("sha256", "mot");'. En cas d'échec,
 * le message ne cite que le fichier et la ligne : les journaux de la CI d'un
 * dépôt public sont publics aussi.
 */

/**
 * Empreintes des mots et paires de mots qui nomment l'infrastructure.
 *
 * @return array<string, true>
 */
function infrastructureEmpreintesInterdites(): array
{
    return array_fill_keys([
        '047de0f543c7e3596bb6dbd0e65f2c30357720a63eca423f08c52fae314f6806',
        'e76f96ad0464976a33e7bfa46ab384521cd0e5229a861bec1b8bdcf009ec7205',
        'ce29c26322abc2113a235381220cb924bc6094a013b8633e4fefa648d53ab649',
        '7f331a0764ad607cd8a54f8f1a683b50df6f2ad91ecf0e1e488f1982a97046e9',
        'f8c744a979c2fa77b3cd192f7cf8f7e02efa13c8e97b0e4e70acbf69aeb38db5',
        '795b104abe3e4134960ca245ded0e617f162c751209347b7f003bc35e062f43e',
        'ec6f049478046f72becb006a2339202eae93911281dd610a7c869adec1ba3297',
        'e851219b75c97d1a161ad6b20c05b82cabb6c3b1b5ab542d9538d0fb1f31ba88',
        'cf6dd0b6bcff0572500cdd087008918592ada20f16f5d17c527c153bb4f63e78',
        'fb7bd76738ceee9ec4ac63ad5ac10956a682974c7b8850fe7920cb0f3539e684',
        '022a36dfadf09ba4bf2549819660fea3ded8a9fc2ac564db0ca90af906b2a29a',
        'dbf3b7bc2694a7276bee556e87bb79a9a1522cc2963bea120c38cc794af5d472',
        'd5e0750cda62843e72511029ecf749600e669eee95d94292f54515566a7bc2a9',
        '3e46ae279ca7c0bbe1336e8d57d0d315e8d2d3c86a28ede38356e78cd3c86599',
        '949deccfe8d12e26486213c1506633ba6a35b3f7db3733981b49ab9b8b1cbfeb',
        '8512e2178be28fd9475da0b76d9d4a683a4ffd7424d2cab606a63f463e0deb6c',
        '10d29f68018b651cd33b886558dfbc001a986f389b00222b70accd42f6a08c0c',
        '924c7db930a53cc7c2a4630e26632b30b8775e9aaff72c467a6a4b7ab4e3b20f',
        '5cfee4b5453450e2af83ad70aa5f716a19148c198f4858c41d1468db7d3d509f',
        '001201f1285b4db603934b0bdf25f79831b922e8d52eeff28d5189a592fea07d',
        'da1dd959e2f83569c781fac67bef41e4819fbddc879ce59651b1e3e96c671248',
    ], true);
}

/**
 * Les numéros des lignes qui nomment l'infrastructure : un mot ou une paire
 * de mots interdits, ou une adresse de la plage partagée de la RFC 6598,
 * celle des réseaux privés superposés. Les exemples d'adresses prennent les plages de
 * documentation (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24).
 *
 * @return list<int>
 */
function infrastructureLignesQuiLaNomment(string $contenu): array
{
    $interdites = infrastructureEmpreintesInterdites();
    $lignes = [];

    foreach (explode("\n", $contenu) as $index => $ligne) {
        if (preg_match('/\b100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.\d{1,3}\.\d{1,3}\b/', $ligne) === 1) {
            $lignes[] = $index + 1;

            continue;
        }

        $mots = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($ligne), -1, PREG_SPLIT_NO_EMPTY);
        $mots = $mots === false ? [] : $mots;

        foreach ($mots as $position => $mot) {
            $paire = isset($mots[$position + 1]) ? $mot.' '.$mots[$position + 1] : null;

            if (isset($interdites[hash('sha256', $mot)]) || ($paire !== null && isset($interdites[hash('sha256', $paire)]))) {
                $lignes[] = $index + 1;

                break;
            }
        }
    }

    return $lignes;
}

/**
 * Les fichiers texte du dépôt, ceux que Git suivrait : .gitignore respecté,
 * dépendances et fichiers produits écartés.
 *
 * @return array<string, string> contenu par chemin relatif
 */
function infrastructureFichiersDuDepot(): array
{
    $fichiers = Finder::create()
        ->files()
        ->in(base_path())
        ->ignoreDotFiles(false)
        ->ignoreVCS(true)
        ->ignoreVCSIgnored(true)
        ->exclude(['vendor', 'node_modules', 'storage', 'bootstrap/cache', 'public/build', '.claude/worktrees']);

    $contenus = [];

    foreach ($fichiers as $fichier) {
        $contenu = (string) file_get_contents($fichier->getPathname());

        if (str_contains(substr($contenu, 0, 8000), "\0")) {
            continue;
        }

        $contenus[$fichier->getRelativePathname()] = $contenu;
    }

    return $contenus;
}

it('reconnaît un nom interdit sans l\'écrire', function (): void {
    // strrev() : le mot n'apparaît pas en clair, même ici.
    expect(infrastructureLignesQuiLaNomment("première ligne\nle ".strrev('san').' de production'))->toBe([2])
        ->and(infrastructureLignesQuiLaNomment('passe par '.implode('.', [100, 100, 100, 100])))->toBe([1])
        ->and(infrastructureLignesQuiLaNomment('un proxy inverse, une pile, un disque lent, 203.0.113.32'))->toBe([]);
});

it('ne nomme l\'infrastructure de production nulle part dans le dépôt', function (): void {
    $fichiers = infrastructureFichiersDuDepot();

    expect(count($fichiers))->toBeGreaterThan(500)
        ->and($fichiers)->toHaveKeys(['README.md', 'docker-compose.prod.yml', 'CHANGELOG.md']);

    $mentions = [];

    foreach ($fichiers as $chemin => $contenu) {
        foreach (infrastructureLignesQuiLaNomment($contenu) as $ligne) {
            $mentions[] = "{$chemin}:{$ligne}";
        }
    }

    expect($mentions)->toBe([], sprintf(
        "Ces lignes nomment l'infrastructure de production, dans un dépôt public. Décrivez ce qu'elle fait "
        ."(un proxy inverse, la pile, le disque de production), pas ce qu'elle est :\n- %s",
        implode("\n- ", $mentions),
    ));
});
