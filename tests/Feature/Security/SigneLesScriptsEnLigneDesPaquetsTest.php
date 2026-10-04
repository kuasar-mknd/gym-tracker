<?php

declare(strict_types=1);

use App\Support\Csp\Nonce\SigneLesScriptsEnLigneDesPaquets;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;

/**
 * Filament, filament-exceptions, le lecteur de journaux et Pulse écrivent des
 * `<script>` nus dans leurs gabarits ; la CSP les bloquerait. Le précompilateur les signe à la
 * compilation, dans le gabarit et non dans la page rendue, pour ne jamais
 * signer un script qu'une donnée injectée aurait glissé dans la réponse
 * (#1920, #1922).
 */
function signatureDesGabaritsPrecompile(string $cheminRelatif, string $gabarit): string
{
    Blade::setPath(base_path($cheminRelatif));

    return new SigneLesScriptsEnLigneDesPaquets()($gabarit);
}

function signatureDesGabaritsAttribut(): string
{
    return 'nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"';
}

it('signe les scripts en ligne des gabarits de Filament, de filament-exceptions, du lecteur de journaux et de Pulse', function (string $cheminRelatif): void {
    $gabarit = "<script>\n    a()\n</script>\n<script data-navigate-once>b()</script>";

    expect(signatureDesGabaritsPrecompile($cheminRelatif, $gabarit))->toBe(
        '<script '.signatureDesGabaritsAttribut().">\n    a()\n</script>\n"
        .'<script data-navigate-once '.signatureDesGabaritsAttribut().'>b()</script>'
    );
})->with([
    'vendor/filament/filament/resources/views/livewire/sidebar.blade.php',
    'vendor/filament/notifications/resources/views/notifications.blade.php',
    'vendor/bezhansalleh/filament-exceptions/resources/views/components/topbar.blade.php',
    'vendor/opcodesio/log-viewer/resources/views/index.blade.php',
    'vendor/laravel/pulse/resources/views/components/theme-switcher.blade.php',
]);

it('laisse tel quel un script qui porte déjà src ou nonce', function (): void {
    $gabarit = '<script src="/js/app.js"></script><script @if (filled($nonce)) nonce="{{ $nonce }}" @endif>c()</script>';

    expect(signatureDesGabaritsPrecompile('vendor/filament/support/resources/views/assets.blade.php', $gabarit))->toBe($gabarit);
});

it('ne touche à aucun autre gabarit', function (string $cheminRelatif): void {
    expect(signatureDesGabaritsPrecompile($cheminRelatif, '<script>d()</script>'))->toBe('<script>d()</script>');
})->with([
    'resources/views/app.blade.php',
    'vendor/laravel/horizon/resources/views/layout.blade.php',
    'resources/views/vendor/pulse/dashboard.blade.php',
    'vendor/filament-voisin/resources/views/page.blade.php',
]);

it('est branché sur le compilateur Blade, qui en fait un nonce lu à chaque rendu', function (): void {
    Blade::setPath(base_path('vendor/filament/filament/resources/views/components/layout/base.blade.php'));

    expect(Blade::compileString('<script>e()</script>'))
        ->toBe('<script nonce="<?php echo e(\Illuminate\Support\Facades\Vite::cspNonce()); ?>">e()</script>');
});

/**
 * filament-exceptions et Pulse écrivent certaines balises en PHP, que leur
 * gabarit recopie brut : le gabarit ne montre que l'appel. Le précompilateur
 * enveloppe l'écho de ces appels-là, et d'eux seuls, dans la signature.
 */
it('enveloppe l’écho brut des balises qu’un paquet écrit en PHP, et lui seul', function (string $cheminRelatif, string $gabarit, string $attendu): void {
    expect(signatureDesGabaritsPrecompile($cheminRelatif, $gabarit))->toBe($attendu);
})->with([
    'le détail d’une exception' => [
        'vendor/bezhansalleh/filament-exceptions/resources/views/view-exception.blade.php',
        "{!! FilamentExceptions::renderCss() !!}\n{!!FilamentExceptions::renderJs()!!}\n{!! \$fallback !!}",
        "{!! FilamentExceptions::renderCss() !!}\n{!! \\".SigneLesScriptsEnLigneDesPaquets::class."::signeLesBalisesDe(FilamentExceptions::renderJs()) !!}\n{!! \$fallback !!}",
    ],
    'la page de Pulse' => [
        'vendor/laravel/pulse/resources/views/components/pulse.blade.php',
        "{!! Pulse::css() !!}\n{!! Pulse::js() !!}",
        '{!! \\'.SigneLesScriptsEnLigneDesPaquets::class."::signeLesBalisesDe(Pulse::css()) !!}\n{!! \\".SigneLesScriptsEnLigneDesPaquets::class.'::signeLesBalisesDe(Pulse::js()) !!}',
    ],
]);

it('n’enveloppe pas ces appels hors des gabarits signés', function (): void {
    expect(signatureDesGabaritsPrecompile('resources/views/app.blade.php', '{!! FilamentExceptions::renderJs() !!}{!! Pulse::js() !!}'))
        ->toBe('{!! FilamentExceptions::renderJs() !!}{!! Pulse::js() !!}');
});

/**
 * livewire.js contient lui-même le texte `<script>`, dans une expression
 * rationnelle et dans un message entre guillemets : le middleware qui
 * réécrivait la page de Pulse y glissait un nonce, fermait la chaîne, et
 * livewire.js ne se chargeait plus. Le contenu d'une balise s'arrête à sa
 * fermeture, comme pour le navigateur.
 */
it('signe chaque balise du fragment, jamais un « <script> » écrit dans le code d’un script', function (): void {
    Vite::useCspNonce('n0nce');

    expect(SigneLesScriptsEnLigneDesPaquets::signeLesBalisesDe(
        '<script type="module">const r = /<script\b[^>]*>/; warn("Alpine\'s `<script>` tag?")</script>'."\n"
        .'<SCRIPT>g()</SCRIPT><script src="/a.js"></script><scripts>h()</scripts><script-x>i()</script-x>'."\n"
        .'<style>p{content:"<style>"}</style><script nonce="autre">j()</script>',
    ))->toBe(
        '<script type="module" nonce="n0nce">const r = /<script\b[^>]*>/; warn("Alpine\'s `<script>` tag?")</script>'."\n"
        .'<SCRIPT nonce="n0nce">g()</SCRIPT><script src="/a.js"></script><scripts>h()</scripts><script-x>i()</script-x>'."\n"
        .'<style nonce="n0nce">p{content:"<style>"}</style><script nonce="autre">j()</script>',
    );
});

it('rend le fragment tel quel sans nonce', function (): void {
    expect(SigneLesScriptsEnLigneDesPaquets::signeLesBalisesDe('<script>a()</script><style>b{}</style>'))
        ->toBe('<script>a()</script><style>b{}</style>');
});
