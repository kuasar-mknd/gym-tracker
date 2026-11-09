<?php

declare(strict_types=1);

use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use Tests\Support\FichiersSuivis;

/*
 * Une réponse du diagnostic n'entre pas dans le dépôt (#2032).
 *
 * Toute réponse du diagnostic porte la note de son enveloppe
 * (`EnveloppeDeDiagnostic::NOTE`) : la trouver dans un fichier suivi, c'est y
 * trouver une réponse recopiée, donc des données de production, expurgées ou
 * non, et du texte venu de l'extérieur, dans un dépôt public. La note ne
 * s'écrit donc que dans sa classe (`reponseDuDiagnosticFichiersPermis()`) ;
 * les tests qui la comparent, ceux de l'enveloppe compris, la lisent par la
 * constante. Un fichier permis qui ne l'écrit pas n'ouvre qu'un trou, là où
 * une réponse collée en données de test passerait : la garde le refuse.
 *
 * La garde la cherche telle qu'elle se lit, telle que la classe l'écrit
 * (l'apostrophe échappée d'une chaîne PHP entre apostrophes), et telle
 * qu'une réponse collée l'écrirait : en JSON, guillemets et lettres
 * accentuées échappés (l'écriture par défaut de PHP, donc celle d'une
 * réponse JSON de Laravel), et en HTML (la page de l'aperçu enregistrée).
 * Son message ne cite que le fichier et la ligne.
 */

/**
 * Les seuls fichiers où la note peut s'écrire : sa classe.
 *
 * @return list<string>
 */
function reponseDuDiagnosticFichiersPermis(): array
{
    return ['app/Services/Diagnostic/EnveloppeDeDiagnostic.php'];
}

/**
 * La note telle qu'elle se lit, telle qu'une chaîne PHP entre apostrophes
 * l'écrit, telle qu'un JSON par défaut l'échappe, et telle que l'HTML
 * l'échappe.
 *
 * @return list<string>
 */
function reponseDuDiagnosticFormesDeLaNote(): array
{
    $enJson = json_encode(EnveloppeDeDiagnostic::NOTE, JSON_THROW_ON_ERROR);

    return array_values(array_unique([
        EnveloppeDeDiagnostic::NOTE,
        addcslashes(EnveloppeDeDiagnostic::NOTE, "'\\"),
        substr($enJson, 1, -1),
        htmlspecialchars(EnveloppeDeDiagnostic::NOTE, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    ]));
}

/**
 * Les numéros des lignes qui portent la note, sous l'une de ses formes.
 *
 * @return list<int>
 */
function reponseDuDiagnosticLignesQuiLaPortent(string $contenu): array
{
    $formes = reponseDuDiagnosticFormesDeLaNote();
    $porteLaNote = static fn (string $texte): bool => array_any($formes, static fn (string $forme): bool => str_contains($texte, $forme));

    if (! $porteLaNote($contenu)) {
        return [];
    }

    $lignes = [];

    foreach (explode("\n", $contenu) as $index => $ligne) {
        if ($porteLaNote($ligne)) {
            $lignes[] = $index + 1;
        }
    }

    return $lignes;
}

it('reconnaît la note telle qu’elle se lit, telle que la classe l’écrit, en JSON et en HTML', function (): void {
    $formes = reponseDuDiagnosticFormesDeLaNote();

    $barre = chr(92);

    expect($formes)->toHaveCount(4)
        ->and($formes[1])->toContain("l{$barre}'ext")
        ->and($formes[2])->toContain("{$barre}u00ab non_fiable {$barre}u00bb")
        ->and($formes[3])->toContain('l&#039;ext');

    foreach ($formes as $forme) {
        expect(reponseDuDiagnosticLignesQuiLaPortent("{\n  \"note\": \"{$forme}\"\n}"))->toBe([2]);
    }

    expect(reponseDuDiagnosticLignesQuiLaPortent('"note": "'.substr(EnveloppeDeDiagnostic::NOTE, 0, 40).'"'))->toBe([]);
});

it('n’écrit la note que dans sa classe', function (): void {
    $fichiers = FichiersSuivis::contenus();

    expect(count($fichiers))->toBeGreaterThan(500)
        ->and($fichiers)->toHaveKeys([
            ...reponseDuDiagnosticFichiersPermis(),
            'resources/views/vendor/mail/html/message.blade.php',
            'lang/vendor/health/fr/notifications.php',
            'public/robots.txt',
            'storage/framework/.gitignore',
        ]);

    foreach (reponseDuDiagnosticFichiersPermis() as $permis) {
        expect(reponseDuDiagnosticLignesQuiLaPortent($fichiers[$permis]))
            ->not->toBe([], "{$permis} est permis sans écrire la note : la permission n'ouvre qu'un trou.");
    }

    $mentions = [];

    foreach (array_diff_key($fichiers, array_flip(reponseDuDiagnosticFichiersPermis())) as $chemin => $contenu) {
        foreach (reponseDuDiagnosticLignesQuiLaPortent($contenu) as $ligne) {
            $mentions[] = "{$chemin}:{$ligne}";
        }
    }

    expect($mentions)->toBe([], sprintf(
        "Ces lignes portent la note de l'enveloppe du diagnostic : une réponse du diagnostic, ou sa note, "
        ."recopiée dans un dépôt public. Retirez-la ; un test la lit par EnveloppeDeDiagnostic::NOTE :\n- %s",
        implode("\n- ", $mentions),
    ));
});
