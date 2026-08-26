<?php

declare(strict_types=1);

/*
 * Une classe `@utility` employee par un composant doit etre declaree.
 *
 * Un navigateur ne se plaint pas d'une classe inconnue : il ne trouve aucune
 * regle et n'applique rien. `glass-badge-warning` a donc vecu longtemps sans
 * declaration, et le badge « En cours » du tableau de bord s'affichait sans
 * aucune couleur — un mot gris sur fond gris, a cote d'un badge « Fait » qui,
 * lui, en avait une.
 *
 * Le symetrique compte autant : `glass-badge-violet` etait declare et lu par
 * personne. Un utilitaire mort n'a pas d'effet visible, mais il se copie — le
 * prochain badge sera ecrit en le prenant pour modele, et heritera d'un lavis a
 * 10 % que rien ne justifie.
 *
 * Ce controle ne regarde que les utilitaires de la charte, ceux ecrits
 * explicitement en `@utility`. Les classes de Tailwind sont generees a la
 * demande et n'ont pas ce probleme.
 */

use Symfony\Component\Finder\Finder;

/**
 * Les utilitaires declares dans la charte.
 *
 * @return list<string>
 */
function utilitairesDeclares(): array
{
    $css = (string) file_get_contents(resource_path('css/app.css'));

    preg_match_all('/^@utility ([a-z][a-z0-9-]*)/m', $css, $trouves);

    return array_values(array_unique($trouves[1]));
}

/**
 * Les utilitaires de la charte reellement employes, et ou.
 *
 * @return array<string, list<string>>
 */
function utilitairesEmployes(): array
{
    $declares = utilitairesDeclares();

    $fichiers = Finder::create()
        ->files()
        ->in([resource_path('js'), resource_path('views')])
        ->name(['*.vue', '*.js', '*.blade.php']);

    $emplois = [];

    foreach ($fichiers as $fichier) {
        $contenu = $fichier->getContents();

        foreach ($declares as $utilitaire) {
            if (preg_match('/\b'.preg_quote($utilitaire, '/').'\b/', $contenu) === 1) {
                $emplois[$utilitaire][] = $fichier->getRelativePathname();
            }
        }
    }

    return $emplois;
}

it('ne laisse aucun utilitaire de la charte sans lecteur', function (): void {
    /*
     * Les familles dont un seul membre suffit a justifier les autres : elles
     * forment un jeu ou l'on choisit, et il serait absurde d'exiger que les
     * huit categories soient toutes employees pour que la huitieme ait le droit
     * d'exister.
     */
    $familles = ['category-fill-', 'plate-fill-'];

    $employes = utilitairesEmployes();

    $orphelins = array_values(array_filter(
        utilitairesDeclares(),
        static function (string $utilitaire) use ($employes, $familles): bool {
            if (array_key_exists($utilitaire, $employes)) {
                return false;
            }

            return array_all($familles, fn (string $famille): bool => ! str_starts_with($utilitaire, $famille));
        }
    ));

    expect($orphelins)->toBe([], sprintf(
        "Ces utilitaires sont declares et personne ne les lit :\n  %s\n\n"
        ."Un utilitaire mort ne se voit pas, et c'est le probleme : il se copie. Le prochain "
        .'composant sera ecrit en le prenant pour modele. Supprimez-le, ou employez-le.',
        implode("\n  ", $orphelins)
    ));
});

it('ne laisse employer aucune classe de charte inexistante', function (): void {
    $declares = utilitairesDeclares();

    $fichiers = Finder::create()
        ->files()
        ->in([resource_path('js'), resource_path('views')])
        ->name(['*.vue', '*.blade.php']);

    /*
     * On ne peut pas verifier TOUTE classe employee — Tailwind en genere des
     * milliers a la demande. On verifie les familles que la charte possede en
     * propre : si `glass-badge-` existe, alors `glass-badge-warning` doit
     * exister aussi.
     */
    $familles = [];

    foreach ($declares as $utilitaire) {
        if (preg_match('/^([a-z]+-[a-z]+)-[a-z0-9-]+$/', $utilitaire, $partie) === 1) {
            $familles[$partie[1]] = true;
        }
    }

    $inconnus = [];

    foreach ($fichiers as $fichier) {
        foreach (array_keys($familles) as $famille) {
            preg_match_all('/\b'.preg_quote($famille, '/').'-[a-z0-9-]+\b/', $fichier->getContents(), $trouves);

            foreach ($trouves[0] as $classe) {
                if (! in_array($classe, $declares, true)) {
                    $inconnus[$classe][] = $fichier->getRelativePathname();
                }
            }
        }
    }

    $rapport = array_map(
        static fn (string $classe, array $ou): string => $classe.' ('.implode(', ', array_unique($ou)).')',
        array_keys($inconnus),
        $inconnus
    );

    expect($rapport)->toBe([], sprintf(
        "Ces classes appartiennent a une famille de la charte et ne sont declarees nulle part :\n  %s\n\n"
        ."Un navigateur ne signale pas une classe inconnue : il n'applique rien, et l'element "
        ."s'affiche sans la couleur qu'on croyait lui donner. C'est ainsi que le badge « En cours » "
        .'est reste incolore.',
        implode("\n  ", $rapport)
    ));
});
