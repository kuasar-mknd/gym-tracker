<?php

declare(strict_types=1);

/*
 * `docs/charte.html` doit correspondre a `resources/css/app.css`.
 *
 * La charte a longtemps ete documentee par une page publiee HORS du depot, que
 * seul son auteur pouvait ouvrir. Sur un depot public, une documentation que
 * personne ne peut lire n'en est pas une — elle est desormais dans `docs/`.
 *
 * Mais une page committee est une COPIE, et une copie diverge. C'est
 * exactement la panne que cette charte existe pour supprimer : deux endroits
 * portant la meme information, libres de s'ecarter sans que rien ne le signale.
 * Il aurait ete singulier de la reintroduire par sa propre documentation.
 *
 * Alors la page n'est pas ecrite, elle est GENEREE, et ce controle regenere en
 * memoire pour comparer. Changer un jeton sans relancer `charte:publier` fait
 * tomber la suite, avec le nom de la commande dans le message.
 *
 * Le meme raisonnement vaut pour les mesures : chaque contraste affiche est
 * recalcule a la generation. La page ne peut pas annoncer 4,5:1 pour une
 * couleur qui en rend 3,2 — ce qui est arrive plusieurs fois dans les
 * commentaires de la feuille avant qu'un controle ne les verifie.
 */

use App\Console\Commands\PublierLaCharte;

it('garde docs/charte.html en accord avec la feuille', function (): void {
    $chemin = base_path(PublierLaCharte::CHEMIN);

    expect($chemin)->toBeReadableFile(sprintf(
        "%s est introuvable.\n\nRegenerez-la : `php artisan charte:publier`.",
        PublierLaCharte::CHEMIN
    ));

    $surDisque = (string) file_get_contents($chemin);
    $attendue = PublierLaCharte::page();

    if ($surDisque === $attendue) {
        expect(true)->toBeTrue();

        return;
    }

    /*
     * Un diff complet de 30 000 caracteres n'aide personne. On rend la
     * PREMIERE ligne qui differe, avec son numero : c'est ce qui permet de voir
     * en un coup d'oeil si la page a pris du retard sur un jeton, ou si le
     * gabarit lui-meme a change.
     */
    $lignesDisque = explode("\n", $surDisque);
    $lignesAttendues = explode("\n", $attendue);
    $ecart = 'longueur differente uniquement';

    foreach ($lignesAttendues as $index => $ligne) {
        if (($lignesDisque[$index] ?? null) !== $ligne) {
            $ecart = sprintf(
                "ligne %d\n    sur disque : %s\n    attendu    : %s",
                $index + 1,
                mb_substr($lignesDisque[$index] ?? '(absente)', 0, 160),
                mb_substr($ligne, 0, 160)
            );

            break;
        }
    }

    expect($surDisque)->toBe($attendue, sprintf(
        "%s a pris du retard sur `resources/css/app.css`.\n\n%s\n\n"
        ."Regenerez-la : `php artisan charte:publier`.\n\n"
        .'Ce controle existe parce qu une documentation committee est une copie, et qu une copie '
        ."diverge. C'est la panne que cette charte a passe une journee a supprimer ; la reintroduire "
        .'par sa propre page de documentation serait dommage.',
        PublierLaCharte::CHEMIN,
        $ecart
    ));
});

it('publie une page qui montre reellement les jetons', function (): void {
    /*
     * Un generateur peut rendre une page valide et VIDE — un gabarit correct
     * autour de rien. Le controle precedent ne le verrait pas : la page sur
     * disque serait vide elle aussi, et les deux coincideraient.
     */
    $page = PublierLaCharte::page();

    $pastilles = substr_count($page, 'class="jeton"');
    $jetons = count(App\Support\Charte::couleursPleines());

    expect($pastilles)->toBe($jetons, sprintf(
        'La page montre %d pastilles pour %d jetons de couleur pleine. Un jeton declare et jamais '
        ."affiche est un jeton que personne ne trouvera, ce qui est le contraire d'une charte.",
        $pastilles,
        $jetons
    ));

    /*
     * On compte les `<td class="num` — une cellule de mesure par surface — et
     * non une chaine collee comme `<tr><td><code>`. Le gabarit est passe d'une
     * concatenation PHP a une vue Blade, qui indente : l'assertion precedente
     * comptait zero sur une page parfaitement correcte, ce qui est le pire
     * genre d'echec, celui qui fait douter du code plutot que du test.
     */
    $rangs = substr_count($page, '<td class="num');
    $apparies = count(App\Support\Charte::surfacesAppariees());

    expect($rangs)->toBe($apparies, sprintf(
        'La page decrit %d surfaces appariees pour %d declarees.',
        $rangs,
        $apparies
    ));
});
