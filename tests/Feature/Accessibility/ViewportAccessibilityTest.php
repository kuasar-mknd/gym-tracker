<?php

declare(strict_types=1);

/**
 * The viewport meta tag is a single line in app.blade.php that is easy to
 * "optimise" back into a zoom lock. WCAG 2.1 SC 1.4.4 requires text to scale to
 * 200%, so pinch-zoom has to stay available to low-vision users.
 */
it('does not prevent users from zooming', function (): void {
    $response = $this->get(route('login'));

    $response->assertSuccessful();

    $viewport = viewportContentFrom($response->getContent());

    expect($viewport)->not->toContain('maximum-scale');
    expect($viewport)->not->toContain('user-scalable=no');
    expect($viewport)->not->toContain('user-scalable=0');
});

it('still opts into the iOS safe-area insets', function (): void {
    $response = $this->get(route('login'));

    // viewport-fit=cover is what makes env(safe-area-inset-*) resolve to real
    // values; without it the layout padding silently collapses to 0 on notched
    // devices and content slides under the home indicator.
    expect(viewportContentFrom($response->getContent()))->toContain('viewport-fit=cover');
});

function viewportContentFrom(string $html): string
{
    expect($html)->toMatch('/<meta\s+name="viewport"/');

    preg_match('/<meta\s+name="viewport"\s+content="([^"]*)"/s', $html, $matches);

    return $matches[1] ?? '';
}
