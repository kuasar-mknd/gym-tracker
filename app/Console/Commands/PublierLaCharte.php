<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Charte;
use App\Support\FamillesDeLaCharte;
use Illuminate\Console\Command;

/**
 * Écrit `docs/charte.html` à partir de `resources/css/app.css`.
 *
 * La charte vivait dans une page publiée hors du dépôt, que seul son auteur
 * pouvait ouvrir. Sur un dépôt public, une documentation que personne ne peut
 * lire n'en est pas une.
 *
 * Elle n'y est pas recopiée pour autant : la page est GÉNÉRÉE depuis la
 * feuille, et chaque contraste y est recalculé. Une page figée aurait menti dès
 * le premier jeton modifié — exactement la divergence que cette charte existe
 * pour supprimer, et il aurait été singulier de la réintroduire par sa propre
 * documentation.
 *
 * Cette commande ne fait qu'assembler. Le HTML est dans
 * `resources/views/docs/charte.blade.php`, le classement des jetons dans
 * `App\Support\FamillesDeLaCharte`, les valeurs et les mesures dans
 * `App\Support\Charte`. Tout cela a d'abord été écrit ICI, et PHP Insights l'a
 * refusé : 138 lignes de HTML dans une seule méthode. Le reproche était juste.
 *
 * `LaPageDeLaCharteEstAJourTest` régénère la page en mémoire et la compare au
 * fichier : la modifier sans relancer cette commande fait tomber la suite.
 */
class PublierLaCharte extends Command
{
    public const CHEMIN = 'docs/charte.html';

    #[\Override]
    protected $signature = 'charte:publier';

    #[\Override]
    protected $description = 'Régénère docs/charte.html depuis resources/css/app.css';

    public function handle(): int
    {
        file_put_contents(base_path(self::CHEMIN), self::page());

        $this->info(sprintf(
            '%s écrit : %d jetons, %d surfaces appariées.',
            self::CHEMIN,
            count(Charte::couleursPleines()),
            count(Charte::surfacesAppariees())
        ));

        return self::SUCCESS;
    }

    /**
     * La page complète, rendue depuis la vue.
     */
    public static function page(): string
    {
        return view('docs.charte', [
            'familles' => self::familles(),
            'apparieesDetail' => self::apparieesDetail(),
            'jetons' => count(Charte::couleursPleines()),
            'apparies' => count(Charte::surfacesAppariees()),
            'gardes' => self::nombreDeGardes(),
        ])->render();
    }

    /**
     * Les familles, chaque jeton accompagné du meilleur texte qu'il peut porter.
     *
     * @return list<array{titre: string, sous: string, jetons: list<array<string, string>>}>
     */
    private static function familles(): array
    {
        $rendues = [];

        foreach (FamillesDeLaCharte::classer(Charte::couleursPleines()) as $famille) {
            $rendues[] = [
                'titre' => $famille['titre'],
                'sous' => $famille['sous'],
                'jetons' => self::pastilles($famille['jetons']),
            ];
        }

        return $rendues;
    }

    /**
     * @param  array<string, string>  $membres
     * @return list<array<string, string>>
     */
    private static function pastilles(array $membres): array
    {
        $encre = Charte::jeton('text-main');
        $pastilles = [];

        foreach ($membres as $nom => $valeur) {
            $surBlanc = Charte::contraste('#ffffff', $valeur);
            $surEncre = Charte::contraste($encre, $valeur);
            $blancGagne = $surBlanc >= $surEncre;

            $pastilles[] = [
                'nom' => $nom,
                'valeur' => $valeur,
                'porte' => $blancGagne ? 'blanc' : 'encre',
                'mesure' => number_format(max($surBlanc, $surEncre), 1, ',', ''),
                'encre' => $blancGagne ? '#ffffff' : $encre,
            ];
        }

        return $pastilles;
    }

    /**
     * Les surfaces appariées, avec leurs valeurs résolues et leur mesure.
     *
     * @return list<array<string, string>>
     */
    private static function apparieesDetail(): array
    {
        $detail = [];

        foreach (Charte::surfacesAppariees() as $nom => $paire) {
            $fond = Charte::jeton($paire['fond']);
            $texte = Charte::jeton($paire['texte']);

            $detail[] = [
                'nom' => $nom,
                'fond' => $paire['fond'],
                'texte' => $paire['texte'],
                'fondHex' => $fond,
                'texteHex' => $texte,
                'mesure' => number_format(Charte::contraste($texte, $fond), 2, ',', ''),
            ];
        }

        return $detail;
    }

    private static function nombreDeGardes(): int
    {
        $fichiers = glob(base_path('tests/Feature/Conventions/*.php'));

        return $fichiers === false ? 0 : count($fichiers);
    }
}
