<?php

declare(strict_types=1);

/*
 * Répartit les fichiers de tests/Browser en N éclats de durée comparable.
 *
 * Usage : php .github/scripts/dusk-eclats.php <éclats> <numéro>
 * Écrit, un par ligne, les fichiers de l'éclat demandé (numéroté à partir de 1).
 *
 * Les poids sont des secondes mesurées en CI, dans dusk-eclats.json ; un
 * fichier absent du tableau reçoit le poids médian, donc un test nouveau est
 * toujours réparti. Glouton sur les poids décroissants, puis par nom : le
 * résultat est déterministe et chaque fichier tombe dans exactement un éclat.
 */
$eclats = (int) ($argv[1] ?? 0);
$numero = (int) ($argv[2] ?? 0);

if ($eclats < 1 || $numero < 1 || $numero > $eclats) {
    fwrite(STDERR, "usage : dusk-eclats.php <éclats> <numéro entre 1 et éclats>\n");
    exit(2);
}

$racine = dirname(__DIR__, 2);
/** @var array<string, int> $poids */
$poids = json_decode((string) file_get_contents(__DIR__.'/dusk-eclats.json'), true, 512, JSON_THROW_ON_ERROR);
$fichiers = glob($racine.'/tests/Browser/*Test.php') ?: [];
sort($fichiers);

$connus = array_values($poids);
sort($connus);
$median = $connus === [] ? 5 : $connus[intdiv(count($connus), 2)];

$peses = [];
foreach ($fichiers as $chemin) {
    $peses[basename($chemin)] = $poids[basename($chemin)] ?? $median;
}
uksort($peses, static fn (string $a, string $b): int => [$peses[$b], $a] <=> [$peses[$a], $b]);

$charges = array_fill(1, $eclats, 0);
$attribution = [];
foreach ($peses as $nom => $secondes) {
    $cible = array_search(min($charges), $charges, true);
    $charges[$cible] += $secondes;
    $attribution[$nom] = $cible;
}

foreach (array_keys($peses) as $nom) {
    if ($attribution[$nom] === $numero) {
        echo 'tests/Browser/'.$nom, PHP_EOL;
    }
}
