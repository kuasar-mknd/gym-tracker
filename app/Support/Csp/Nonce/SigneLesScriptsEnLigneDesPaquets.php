<?php

declare(strict_types=1);

namespace App\Support\Csp\Nonce;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;

/**
 * Précompilateur Blade : signe du nonce de la requête les scripts en ligne que
 * des paquets écrivent sans nonce dans leurs propres gabarits.
 *
 * Filament (le thème forcé, l'état replié des groupes du menu, l'alerte de
 * modifications non enregistrées, les notifications), filament-exceptions, le
 * lecteur de journaux et Pulse écrivent des `<script>` nus. Sous la CSP de
 * production, sans 'unsafe-inline', le navigateur les bloque : le menu du
 * panneau levait une erreur (#1920), et le lecteur de journaux restait vide,
 * faute de `window.LogViewer` (#1922).
 *
 * La signature se fait à la compilation, dans le gabarit, jamais sur la
 * réponse rendue : signer chaque `<script>` de la page signerait aussi celui
 * qu'une donnée injectée y aurait glissé, ce contre quoi la CSP est là. Seuls
 * les gabarits de ces paquets sont touchés, et seuls leurs scripts en ligne :
 * un script qui porte déjà `src` ou `nonce` reste tel quel.
 *
 * Un paquet peut aussi écrire ses balises en PHP, que le gabarit recopie brut
 * (`{!! … !!}`) : le gabarit ne montre alors que l'appel. Ces appels-là, et eux
 * seuls, sont nommés dans `BALISES_ECRITES_PAR_DU_PHP` ; le précompilateur
 * enveloppe leur écho dans `signeLesBalisesDe()`, qui signe les balises de ce
 * que l'appel rend — des fichiers du paquet, jamais une donnée de la requête.
 *
 * Une vue déjà compilée n'est recompilée que si son gabarit change : après un
 * changement de ce précompilateur, `php artisan view:clear` en local (l'image,
 * elle, compile ses vues à neuf).
 */
final class SigneLesScriptsEnLigneDesPaquets
{
    /**
     * Les dossiers, relatifs à la racine du dépôt, dont les gabarits sont
     * signés.
     *
     * @var list<string>
     */
    private const array DOSSIERS_SIGNES = [
        'vendor/filament/',
        'vendor/bezhansalleh/filament-exceptions/',
        'vendor/opcodesio/log-viewer/',
        'vendor/laravel/pulse/',
    ];

    /**
     * Les appels de paquet qui écrivent en PHP des balises `<script>` ou
     * `<style>` nues, tels que les gabarits signés les recopient brut.
     *
     * - `FilamentExceptions::renderJs()` recopie tout le JavaScript du paquet,
     *   dont `window.highlight` qui colore la pile du détail d'une exception,
     *   dans un `<script type="module">` ;
     * - `Pulse::js()` recopie livewire.js et pulse.js, `Pulse::css()` sa
     *   feuille de style.
     *
     * N'y ajouter jamais un appel qui mêle à ses balises une donnée de la
     * requête : tout ce qu'il rend serait signé.
     *
     * @var list<string>
     */
    private const array BALISES_ECRITES_PAR_DU_PHP = [
        'FilamentExceptions::renderJs()',
        'Pulse::css()',
        'Pulse::js()',
    ];

    public function __invoke(string $gabarit): string
    {
        if (! $this->estUnGabaritSigne((string) Blade::getPath())) {
            return $gabarit;
        }

        $gabarit = (string) preg_replace_callback(
            '/<script\b([^>]*)>/i',
            static function (array $balise): string {
                if (preg_match('/\b(?:src|nonce)\s*=/i', $balise[1]) === 1) {
                    return $balise[0];
                }

                return '<script'.$balise[1].' nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">';
            },
            $gabarit,
        );

        $appels = implode('|', array_map(
            static fn (string $appel): string => preg_quote($appel, '/'),
            self::BALISES_ECRITES_PAR_DU_PHP,
        ));

        return (string) preg_replace_callback(
            '/\{!!\s*('.$appels.')\s*!!\}/',
            static fn (array $echo): string => '{!! \\'.self::class.'::signeLesBalisesDe('.$echo[1].') !!}',
            $gabarit,
        );
    }

    /**
     * Signe du nonce de la requête chaque balise `<script>` ou `<style>` d'un
     * fragment qu'un paquet a écrit, découpée comme le navigateur la découpe :
     * le contenu d'une balise s'arrête à la première fermeture du même nom, si
     * bien qu'un `<script>` écrit dans le code d'un script n'est jamais pris
     * pour une balise. livewire.js en contient trois : le middleware qui
     * réécrivait la page de Pulse y glissait un `nonce="…"`, au milieu d'une
     * chaîne entre guillemets, et livewire.js ne se chargeait plus.
     *
     * Parcours linéaire plutôt qu'une seule expression rationnelle sur tout le
     * fragment : celui de filament-exceptions pèse plus de 600 Kio, celui de
     * Pulse plus de 800, et une expression qui échoue rend une chaîne vide. Sans
     * nonce (CSP coupée), le fragment ressort tel quel.
     */
    public static function signeLesBalisesDe(string $fragment): string
    {
        $nonce = Vite::cspNonce();

        if (! is_string($nonce) || $nonce === '') {
            return $fragment;
        }

        $signe = '';
        $position = 0;

        while (preg_match('/<(script|style)(?=[\s\/>])/i', $fragment, $trouve, PREG_OFFSET_CAPTURE, $position) === 1) {
            $ouverture = $trouve[0][1];
            $nom = $trouve[1][0];
            $finDeBalise = strpos($fragment, '>', $ouverture);

            if ($finDeBalise === false) {
                break;
            }

            $debutDesAttributs = $ouverture + 1 + strlen($nom);
            $attributs = substr($fragment, $debutDesAttributs, $finDeBalise - $debutDesAttributs);

            $signe .= substr($fragment, $position, $ouverture - $position);
            $signe .= preg_match('/\b(?:src|nonce)\s*=/i', $attributs) === 1
                ? substr($fragment, $ouverture, $finDeBalise - $ouverture + 1)
                : '<'.$nom.$attributs.' nonce="'.e($nonce).'">';

            $fermeture = stripos($fragment, '</'.$nom, $finDeBalise + 1);
            $position = $fermeture === false ? strlen($fragment) : $fermeture;
            $signe .= substr($fragment, $finDeBalise + 1, $position - $finDeBalise - 1);
        }

        return $signe.substr($fragment, $position);
    }

    private function estUnGabaritSigne(string $chemin): bool
    {
        $chemin = str_replace('\\', '/', $chemin);
        $racine = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return array_any(self::DOSSIERS_SIGNES, fn (string $dossier): bool => str_starts_with($chemin, $racine.$dossier));
    }
}
