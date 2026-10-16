<?php

declare(strict_types=1);

namespace App\Support\Csp;

use Illuminate\Support\Facades\Blade;

/**
 * Précompilateur Blade : retire des gabarits de Horizon, Pulse et Telescope
 * les balises `<link>` et `<img>` qui chargent une ressource d'une autre
 * origine.
 *
 * Les trois tableaux de bord prennent leur fonte sur un site de polices tiers
 * (une préconnexion et une feuille de style), et la carte des requêtes
 * sortantes de Pulse l'icône de chaque hôte sur un service d'icônes : chaque
 * page envoyait à ces sites l'adresse IP et le navigateur de l'administrateur.
 * Aucun réglage des paquets ne les retire.
 *
 * La CSP seule ne suffisait pas. Elle bloque la feuille de style, mais pas la
 * préconnexion, qu'aucune directive ne couvre : le navigateur ouvrait quand
 * même la connexion au site de polices. Elle laissait en outre une violation
 * par page dans la console, et dans le rapport CSP si `CSP_REPORT_URI` était
 * posée. Recopier le gabarit dans `resources/views/vendor` l'aurait figé : il
 * aurait dérivé à chaque mise à jour du paquet. Le précompilateur ne retire que
 * ces balises, du gabarit du paquet tel qu'il est livré, à sa compilation ;
 * `RessourcesDistantesDesTableauxDeBordTest` compile chaque gabarit des trois
 * paquets et refuse toute ressource d'une autre origine qui y resterait.
 *
 * Sans la fonte, les tableaux de bord prennent la police sans empattement du
 * système, que leurs feuilles prévoient (`font-family: Figtree, sans-serif`).
 * Une adresse que le gabarit calcule à l'exécution (`src="{{ … }}"`) n'est pas
 * vue ici : la CSP la bloque, faute d'origine tierce permise.
 *
 * Si le motif échoue (PCRE rend `null`), le gabarit ressort tel quel : un
 * tableau de bord vide serait pire qu'une balise que la CSP bloque.
 *
 * Une vue déjà compilée n'est recompilée que si son gabarit change : après un
 * changement de ce précompilateur, `php artisan view:clear` en local (l'image,
 * elle, compile ses vues à neuf).
 */
final class RetireLesRessourcesDistantesDesPaquets
{
    /**
     * Les dossiers, relatifs à la racine du dépôt, dont les gabarits sont
     * nettoyés.
     */
    private const array DOSSIERS = [
        'vendor/laravel/horizon/',
        'vendor/laravel/pulse/',
        'vendor/laravel/telescope/',
    ];

    /**
     * Une balise `<link>` ou `<img>` entière, attributs entre guillemets
     * compris : un `>` dans une valeur (`{{ $a->b }}`) ne la coupe pas.
     */
    private const string BALISE = '/<(?:link|img)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i';

    /**
     * Une adresse absolue ou relative au protocole dans l'attribut qui charge
     * la ressource : elle mène toujours hors de l'application, dont le gabarit
     * d'un paquet ne connaît pas l'origine.
     */
    private const string ADRESSE_DISTANTE = '/\s(?:href|src|srcset)\s*=\s*["\']?\s*(?:https?:)?\/\//i';

    public function __invoke(string $gabarit): string
    {
        if (! $this->estUnGabaritNettoye((string) Blade::getPath())) {
            return $gabarit;
        }

        return preg_replace_callback(
            self::BALISE,
            static fn (array $balise): string => preg_match(self::ADRESSE_DISTANTE, $balise[0]) === 1 ? '' : $balise[0],
            $gabarit,
        ) ?? $gabarit;
    }

    private function estUnGabaritNettoye(string $chemin): bool
    {
        $chemin = str_replace('\\', '/', $chemin);
        $racine = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return array_any(self::DOSSIERS, fn (string $dossier): bool => str_starts_with($chemin, $racine.$dossier));
    }
}
