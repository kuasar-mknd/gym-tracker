<?php

declare(strict_types=1);

/**
 * A service worker may only claim a scope at or below its own path, unless the
 * server says otherwise. This one is built into public/build, so left alone it
 * governs /build/ and controls no page of the application at all — which is why
 * a new build never reached the installed app and reinstalling was the only
 * cure.
 *
 * Two things have to line up, and for a while only one of them did: the file
 * must be served from / with Service-Worker-Allowed, AND the client must ask
 * for that URL. vite.config's buildBase still pointed the registration at
 * /build/sw.js, so the browser refused the root scope four times per page load
 * while this route sat unused.
 */
describe('the service worker route', function (): void {
    it('serves the worker with permission to govern the whole app', function (): void {
        $built = public_path('build/sw.js');

        if (! is_file($built)) {
            $this->markTestSkipped('needs a production build (npm run build)');
        }

        $this->get('/sw.js')
            ->assertOk()
            ->assertHeader('Service-Worker-Allowed', '/')
            ->assertHeader('Content-Type', 'application/javascript');
    });

    it('does not pretend to serve a worker that was never built', function (): void {
        $built = public_path('build/sw.js');
        $backup = $built.'.testing-backup';

        if (! is_file($built)) {
            $this->markTestSkipped('needs a production build (npm run build)');
        }

        rename($built, $backup);

        try {
            $this->get('/sw.js')->assertNotFound();
        } finally {
            rename($backup, $built);
        }
    });
});

/**
 * The registration URL is decided by vite.config, not by the route above, and
 * the two were pointing at different files. Guarding the built artefact is the
 * only place the mismatch is visible.
 */
describe('the built registration', function (): void {
    it('asks for the URL that carries the header', function (): void {
        $bundles = glob(public_path('build/assets/main-*.js')) ?: [];

        if ($bundles === []) {
            $this->markTestSkipped('needs a production build (npm run build)');
        }

        $source = implode('', array_map(fn (string $path): string => (string) file_get_contents($path), $bundles));

        expect($source)->toContain('/sw.js')
            ->and($source)->not->toContain('/build/sw.js');
    });
});
