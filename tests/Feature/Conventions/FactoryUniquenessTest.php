<?php

declare(strict_types=1);

/*
 * `fake()->unique()` ne tient pas la promesse que son nom fait croire.
 *
 * Il ne memorise que ce qu'il a tire DANS UNE INSTANCE Faker, et cette instance
 * est reconstruite a chaque test. La contrainte d'unicite, elle, vit dans la
 * base et couvre toute l'execution. L'un oublie entre deux tests, pas l'autre.
 *
 * En execution ordinaire ca passe souvent, ce qui est le pire des cas : le
 * defaut n'apparait que quand la suite grossit, tourne en parallele, ou est
 * rejouee en boucle — c'est-a-dire sous mutation testing, ou il rendait
 * `pest --mutate` inutilisable (#1366). Mesure, instance reconstruite a chaque
 * tirage comme entre deux tests, 20 campagnes par taille :
 *
 *   200 tirages : 1 campagne sur 20 collisionne
 *   500         : 2
 *   1000        : 9
 *   2000        : 19
 *
 * Quatre fabriques portaient ce defaut. Trois ont ete corrigees une par une
 * (#1425, #1366), chacune avec une longue explication en commentaire — et la
 * quatrieme est passee au travers a chaque fois. Une lecon ecrite dans un
 * commentaire ne s'execute nulle part : ce fichier est la pour qu'elle
 * s'execute.
 *
 * Ce qu'il faut faire a la place : supprimer le vivier au lieu de parier
 * dessus, avec un suffixe aleatoire large — `Str::random(12)` — plutot que de
 * compter sur la memoire d'une instance.
 */

/**
 * Les fichiers de fabrique, ou une erreur bruyante si le dossier a bouge.
 *
 * `glob()` rend `false` sur erreur et `[]` sur un dossier vide. Les deux
 * rendraient le garde ci-dessous vert et muet, ce qui est la facon la plus
 * courante dont une convention cesse de proteger sans que personne ne le
 * remarque — le meme piege que la sonde de #1418, verte parce qu'elle
 * n'envoyait rien.
 *
 * @return list<string>
 */
function fichiersDeFabrique(): array
{
    $fichiers = glob(database_path('factories/*Factory.php'));

    if ($fichiers === false || $fichiers === []) {
        throw new RuntimeException(
            'Aucune fabrique trouvee dans database/factories. Le dossier a-t-il ete deplace ? '
            .'Sans fichiers a lire, le garde ci-dessous passerait sans rien verifier.'
        );
    }

    return $fichiers;
}

it('n’emploie pas fake()->unique() dans les fabriques', function (): void {
    $fautives = [];

    foreach (fichiersDeFabrique() as $fichier) {
        $lignes = file($fichier);

        if ($lignes === false) {
            throw new RuntimeException("Fabrique illisible : {$fichier}");
        }

        foreach ($lignes as $numero => $ligne) {
            /*
             * Les lignes de commentaire sont ecartees : trois fabriques citent
             * `fake()->unique()` pour expliquer pourquoi elles ne l'emploient
             * pas, et un garde qui punirait son propre mode d'emploi serait
             * retire dans la semaine.
             */
            if (preg_match('/^\s*(\*|\/\/|\/\*)/', $ligne) === 1) {
                continue;
            }

            if (preg_match('/->\s*unique\s*\(/', $ligne) === 1) {
                $fautives[] = sprintf('%s:%d — %s', basename($fichier), $numero + 1, trim($ligne));
            }
        }
    }

    expect($fautives)->toBe([], sprintf(
        "%d appel(s) a unique() dans les fabriques. Il ne se souvient pas d'un test a l'autre, "
        ."contrairement a la contrainte en base :\n- %s",
        count($fautives),
        implode("\n- ", $fautives),
    ));
});

/**
 * Ce test dit tout haut ce que `fichiersDeFabrique()` verifie tout bas, pour
 * qu'un dossier renomme fasse tomber un test qui le nomme plutot qu'un garde
 * qui se tait.
 */
it('regarde bien un dossier de fabriques peuplé', function (): void {
    expect(fichiersDeFabrique())->not->toBeEmpty();
});
