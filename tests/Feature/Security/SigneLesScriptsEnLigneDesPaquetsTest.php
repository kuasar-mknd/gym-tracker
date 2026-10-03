<?php

declare(strict_types=1);

use App\Support\Csp\Nonce\SigneLesScriptsEnLigneDesPaquets;
use Illuminate\Support\Facades\Blade;

/**
 * Filament et le lecteur de journaux écrivent des `<script>` nus dans leurs
 * gabarits ; la CSP les bloquerait. Le précompilateur les signe à la
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

it('signe les scripts en ligne des gabarits de Filament, de filament-exceptions et du lecteur de journaux', function (string $cheminRelatif): void {
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
    'vendor/laravel/pulse/resources/views/dashboard.blade.php',
    'vendor/filament-voisin/resources/views/page.blade.php',
]);

it('est branché sur le compilateur Blade, qui en fait un nonce lu à chaque rendu', function (): void {
    Blade::setPath(base_path('vendor/filament/filament/resources/views/components/layout/base.blade.php'));

    expect(Blade::compileString('<script>e()</script>'))
        ->toBe('<script nonce="<?php echo e(\Illuminate\Support\Facades\Vite::cspNonce()); ?>">e()</script>');
});
