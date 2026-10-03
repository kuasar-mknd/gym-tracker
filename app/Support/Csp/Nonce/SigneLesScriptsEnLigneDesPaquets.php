<?php

declare(strict_types=1);

namespace App\Support\Csp\Nonce;

use Illuminate\Support\Facades\Blade;

/**
 * Précompilateur Blade : signe du nonce de la requête les scripts en ligne que
 * des paquets écrivent sans nonce dans leurs propres gabarits.
 *
 * Filament (le thème forcé, l'état replié des groupes du menu, l'alerte de
 * modifications non enregistrées, les notifications), filament-exceptions et
 * le lecteur de journaux écrivent des `<script>` nus. Sous la CSP de
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
 * Une vue déjà compilée n'est recompilée que si son gabarit change : après la
 * mise en place de ce précompilateur, `php artisan view:clear` en local
 * (l'image, elle, compile ses vues à neuf).
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
    ];

    public function __invoke(string $gabarit): string
    {
        if (! $this->estUnGabaritSigne((string) Blade::getPath())) {
            return $gabarit;
        }

        return (string) preg_replace_callback(
            '/<script\b([^>]*)>/i',
            static function (array $balise): string {
                if (preg_match('/\b(?:src|nonce)\s*=/i', $balise[1]) === 1) {
                    return $balise[0];
                }

                return '<script'.$balise[1].' nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">';
            },
            $gabarit,
        );
    }

    private function estUnGabaritSigne(string $chemin): bool
    {
        $chemin = str_replace('\\', '/', $chemin);
        $racine = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return array_any(self::DOSSIERS_SIGNES, fn (string $dossier): bool => str_starts_with($chemin, $racine.$dossier));
    }
}
