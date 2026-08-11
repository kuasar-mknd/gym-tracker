<?php

declare(strict_types=1);

/**
 * Inertia resolves page components against `inertia.pages.paths`, and v3
 * lowered its default from js/Pages to js/pages. macOS answers to both, Linux
 * to one — so a wrong path here passes every test on a developer's machine and
 * fails every `assertInertia()->component()` on CI, with a message about a
 * missing page rather than about a misconfigured path.
 *
 * is_dir() cannot see this: it inherits the filesystem's own case rules, which
 * is exactly the thing that differs. The directory entry is read from its
 * parent instead, so the comparison is byte-for-byte on any platform.
 */
it('points at page directories that exist with the case they are written in', function (): void {
    $paths = config('inertia.pages.paths');

    expect($paths)->toBeArray()->not->toBeEmpty();

    foreach ($paths as $path) {
        $parent = dirname((string) $path);
        $name = basename((string) $path);

        expect(is_dir($parent))->toBeTrue("The parent of a configured page path is missing: {$parent}");

        $entries = array_values(array_diff(scandir($parent) ?: [], ['.', '..']));

        // in_array rather than expect()->toContain(): toContain is variadic, so
        // a message passed alongside the needle becomes a second needle.
        expect(in_array($name, $entries, true))->toBeTrue(
            "config('inertia.pages.paths') lists {$path}, and {$parent} holds no entry spelled exactly \"{$name}\". "
            .'On a case-insensitive filesystem this resolves anyway and every Inertia page assertion passes; on CI none do.'
        );
    }
});

/**
 * The path being right is only half of it — the finder has to agree. This asks
 * the object the assertion helper actually calls.
 */
it('resolves a real page through the finder the test helper uses', function (): void {
    $found = app('inertia.view-finder')->find('Dashboard');

    expect($found)->toBeString()
        ->and(file_exists($found))->toBeTrue();
});
