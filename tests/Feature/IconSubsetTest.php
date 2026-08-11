<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * The self-hosted Material Symbols face carries only the icons this app draws
 * — 9.7 KiB against the 1,099 KiB variable face it used to fetch from Google.
 *
 * The cost of that is a new way to be wrong: an icon added to a component and
 * not to the subset renders as its own name, spelled out in the middle of the
 * interface. That is a silent, purely visual break, which no other test in this
 * suite would notice.
 *
 * So the subset is not a comment — it is checked. Extraction here is
 * deliberately wider than the generator's: it also reads names out of the
 * ternaries inside a mustache, which is exactly where the first pass lost
 * `visibility`, `visibility_off`, `trending_down` and `pause`.
 */

/** @return list<string> */
function iconNamesInSource(): array
{
    $names = [];

    $files = Finder::create()
        ->files()
        ->in(resource_path('js'))
        ->name(['*.vue', '*.js']);

    foreach ($files as $file) {
        $source = $file->getContents();

        /**
         * Everything an element carrying the icon class can put on screen:
         * the bare ligature, and the quoted branches of
         * `{{ open ? 'visibility_off' : 'visibility' }}`.
         *
         * Two things this has to survive, both of which broke an earlier
         * version. The class is spelled "material-symbols-outlined", which
         * does not contain "icon", so filtering lines on that word drops every
         * ternary — that is how `visibility`, `visibility_off`,
         * `trending_down` and `pause` went missing. And Prettier writes the
         * closing tag as `</span\n>`, so requiring a contiguous `</span>`
         * loses `add_circle`, `arrow_forward_ios`, `autorenew` and `stars`.
         */
        preg_match_all('/material-symbols-outlined(.{0,600}?)<\/span/s', $source, $spans);

        foreach ($spans[1] as $span) {
            // The ligature: after the tag's `>`, or alone on its own line.
            preg_match_all('/(?:>|^)\s*([a-z][a-z0-9_]{2,40})\s*$/m', $span, $bare);
            $names = [...$names, ...$bare[1]];

            // Only the branches of a mustache count as names; a quoted value
            // anywhere else in the tag is a class or a colour.
            preg_match_all('/\{\{(.*?)\}\}/s', $span, $mustaches);

            foreach ($mustaches[1] as $expression) {
                preg_match_all('/[\'"`]([a-z][a-z0-9_]{2,30})[\'"`]/', $expression, $branch);
                $names = [...$names, ...$branch[1]];
            }
        }

        /**
         * The maps and lists an icon name is looked up from — `typeIcons`,
         * `themeIcons`, `icon:` keys, the habit picker array. Over-collecting
         * here is the safe direction: a false positive costs one line in the
         * subset list, a false negative costs a word drawn where a glyph
         * belongs.
         */
        foreach (explode("\n", $source) as $line) {
            if (! preg_match('/icon/i', $line)) {
                continue;
            }

            preg_match_all('/[\'"`]([a-z][a-z0-9_]{2,30})[\'"`]/', $line, $quoted);
            $names = [...$names, ...$quoted[1]];
        }

        // The habit picker is a bare array of names with no `icon` on the lines.
        if (preg_match('/const icons = \[(.*?)\]/s', $source, $picker)) {
            preg_match_all('/[\'"]([a-z][a-z0-9_]{2,30})[\'"]/', $picker[1], $chosen);
            $names = [...$names, ...$chosen[1]];
        }
    }

    return array_values(array_unique($names));
}

/**
 * Names the wide sweep above picks up that are not icons at all — CSS colours,
 * component props, type keys. Listed rather than pattern-matched, so that
 * adding one is a deliberate act with a reader's judgement behind it.
 */
const NOT_ICONS = [
    'icon', 'icons', 'class', 'true', 'false', 'null', 'string', 'number', 'object',
    'default', 'template', 'div', 'span', 'button', 'type', 'name', 'color', 'size',
    'label', 'title', 'text', 'small', 'medium', 'large', 'left', 'right', 'center',
    'top', 'bottom', 'dark', 'light', 'system', 'strength', 'cardio', 'timed',
    'distance', 'primary', 'secondary', 'danger', 'emerald', 'red', 'ghost', 'value',
    // `{{ status === 'running' ? 'pause' : 'play_arrow' }}` — the operand of the
    // comparison, not one of the two names the branch can render.
    'running',
];

it('ships every icon the interface can render', function (): void {
    $subset = collect(file(resource_path('fonts/material-symbols.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->reject(fn (string $line): bool => str_starts_with($line, '#'))
        ->map(fn (string $line): string => trim($line))
        ->filter()
        ->all();

    expect($subset)->not->toBeEmpty();

    $used = array_diff(iconNamesInSource(), NOT_ICONS);
    $missing = array_values(array_diff($used, $subset));

    expect($missing)->toBe(
        [],
        'These names are rendered but absent from the self-hosted subset, so they would '
        .'appear as words instead of icons. Add them to resources/fonts/material-symbols.txt '
        .'and regenerate the font: '.implode(', ', $missing)
    );
});

/**
 * The other direction is only waste, not breakage — but a subset that drifts
 * loose from what the app draws stops being reviewable, and every stale entry
 * is one nobody can tell from a needed one.
 */
it('does not carry icons nothing renders', function (): void {
    $subset = collect(file(resource_path('fonts/material-symbols.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->reject(fn (string $line): bool => str_starts_with($line, '#'))
        ->map(fn (string $line): string => trim($line))
        ->filter()
        ->all();

    $unused = array_values(array_diff($subset, iconNamesInSource()));

    expect($unused)->toBe([], 'Unused entries in the icon subset: '.implode(', ', $unused));
});
