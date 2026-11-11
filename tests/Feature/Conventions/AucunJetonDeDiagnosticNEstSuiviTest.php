<?php

declare(strict_types=1);

use App\Services\Diagnostic\Expurgation\MasqueLesSecrets;
use Tests\Support\FichiersSuivis;

/*
 * Aucun jeton de diagnostic complet n'entre dans le dépôt (#2032).
 *
 * Le dépôt est public : un jeton recopié dans un test, une note, une sortie
 * collée ou un fichier d'exemple ouvrirait la lecture du diagnostic à qui le
 * lit, jusqu'à sa révocation. Un test qui a besoin d'un jeton le bâtit par
 * concaténation (`ExpurgeLeTexteTest`, `JetonsHorsDesExceptionsTest`).
 *
 * La garde reconnaît le jeton au motif même que masquent l'expurgation du
 * diagnostic et la table des exceptions (`MasqueLesSecrets::MOTIF_DU_JETON`),
 * une seule source. En cas d'échec, son message ne cite que le fichier et la
 * ligne, comme `LInfrastructureNeSeNommePasTest` : les journaux de la CI
 * d'un dépôt public sont publics aussi, et un jeton qu'ils recopieraient
 * fuirait une seconde fois.
 */

/**
 * Les numéros des lignes qui portent un jeton de diagnostic complet.
 *
 * @return list<int>
 */
function jetonsSuivisLignesQuiEnPortent(string $contenu): array
{
    if (preg_match(MasqueLesSecrets::MOTIF_DU_JETON, $contenu) !== 1) {
        return [];
    }

    $lignes = [];

    foreach (explode("\n", $contenu) as $index => $ligne) {
        if (preg_match(MasqueLesSecrets::MOTIF_DU_JETON, $ligne) === 1) {
            $lignes[] = $index + 1;
        }
    }

    return $lignes;
}

it('reconnaît un jeton complet sans l’écrire', function (): void {
    // Par concaténation : le jeton n'apparaît pas en clair, même ici.
    $identifiant = str_repeat('m4', 6);
    $secret = str_repeat('Rt7', 16);
    $jeton = 'gtdiag_'.$identifiant.'_'.$secret;

    expect(jetonsSuivisLignesQuiEnPortent("première ligne\nAuthorization: Bearer ".$jeton))->toBe([2])
        ->and(jetonsSuivisLignesQuiEnPortent('{"jeton":"'.$jeton.'"}'))->toBe([1])
        ->and(jetonsSuivisLignesQuiEnPortent('gtdiag_'.$identifiant.'_'.substr($secret, 1)))->toBe([])
        ->and(jetonsSuivisLignesQuiEnPortent('gtdiag_'.strtoupper($identifiant).'_'.$secret))->toBe([])
        ->and(jetonsSuivisLignesQuiEnPortent("le format : gtdiag_<identifiant>_<secret>\n'gtdiag_'.\$identifiant"))->toBe([]);
});

it('ne suit aucun jeton de diagnostic complet', function (): void {
    $fichiers = FichiersSuivis::contenus();

    expect(count($fichiers))->toBeGreaterThan(500)
        ->and($fichiers)->toHaveKeys([
            'app/Services/Diagnostic/Expurgation/MasqueLesSecrets.php',
            'tests/Unit/Services/Diagnostic/ExpurgeLeTexteTest.php',
            'CHANGELOG.md',
            'resources/views/vendor/mail/html/message.blade.php',
            'lang/vendor/health/fr/notifications.php',
            'public/robots.txt',
            'storage/framework/.gitignore',
        ]);

    $mentions = [];

    foreach ($fichiers as $chemin => $contenu) {
        foreach (jetonsSuivisLignesQuiEnPortent($contenu) as $ligne) {
            $mentions[] = "{$chemin}:{$ligne}";
        }
    }

    expect($mentions)->toBe([], sprintf(
        'Ces lignes portent un jeton de diagnostic complet, dans un dépôt public. Retirez-le et révoquez-le ; '
        ."un test bâtit le sien par concaténation :\n- %s",
        implode("\n- ", $mentions),
    ));
});
