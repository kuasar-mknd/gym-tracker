<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Rebuilds the self-hosted Material Symbols subset from the icon list.
 *
 * The subset carries only the icons this app draws — around 10 KiB against the
 * 1,099 KiB variable face it used to fetch from Google on every cold start. The
 * saving is worth having, but it introduced a way to be silently wrong: the
 * list is the intent, the .woff2 is the artifact, and nothing tied them
 * together. An icon added to the list without regenerating the font renders as
 * its own name spelled out in the interface.
 *
 * So this command writes both the font and a checksum of the list that produced
 * it, and IconSubsetTest fails when the two drift apart. Regenerating is now one
 * command rather than a curl incantation in a comment.
 */
class SyncIconFont extends Command
{
    #[\Override]
    protected $signature = 'icons:sync {--check : Verify the font matches the list without fetching}';

    #[\Override]
    protected $description = 'Rebuild the self-hosted Material Symbols subset from resources/fonts/material-symbols.txt';

    /**
     * Google serves woff2 only to user agents it believes support it; the
     * default client string gets a ttf back, which the @font-face rule then
     * fails to load.
     */
    private const string USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';

    public function handle(): int
    {
        $names = self::iconNames();

        if ($names === []) {
            $this->error('The icon list is empty.');

            return self::FAILURE;
        }

        $expected = self::checksum($names);

        if ($this->option('check')) {
            return $this->verify($expected);
        }

        $css = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->get('https://fonts.googleapis.com/css2', [
                'family' => 'Material Symbols Outlined',
                'icon_names' => implode(',', $names),
            ]);

        if ($css->failed()) {
            $this->error("Google Fonts answered {$css->status()}.");

            return self::FAILURE;
        }

        // The URL no longer ends in .woff2 — Google now serves subsets from
        // /l/font?kit=…, so the format is read from the rule, not the path.
        if (preg_match("/url\((https:\/\/[^)]+)\).*?format\('woff2'\)/s", $css->body(), $rule) !== 1) {
            $this->error('No woff2 source in the returned @font-face rule.');

            return self::FAILURE;
        }

        $font = Http::withHeaders(['User-Agent' => self::USER_AGENT])->get($rule[1]);

        if ($font->failed() || ! str_starts_with($font->body(), 'wOF2')) {
            $this->error('The downloaded file is not woff2.');

            return self::FAILURE;
        }

        file_put_contents(self::fontPath(), $font->body());
        file_put_contents(self::checksumPath(), $expected."\n");

        $this->info(sprintf(
            '%d icons, %s KiB written to %s.',
            count($names),
            number_format(strlen($font->body()) / 1024, 1),
            'resources/fonts/material-symbols-outlined.woff2'
        ));

        return self::SUCCESS;
    }

    private function verify(string $expected): int
    {
        $recorded = is_readable(self::checksumPath())
            ? trim((string) file_get_contents(self::checksumPath()))
            : '';

        if ($recorded !== $expected) {
            $this->error('The font was built from a different icon list. Run `artisan icons:sync`.');

            return self::FAILURE;
        }

        $this->info('The font matches the icon list.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    public static function iconNames(): array
    {
        $lines = file(self::listPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $names = array_filter(
            array_map(trim(...), $lines),
            fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')
        );

        // sort() reindexes, so what comes back is already a list.
        sort($names);

        return $names;
    }

    /** @param  list<string>  $names */
    public static function checksum(array $names): string
    {
        return hash('sha256', implode("\n", $names));
    }

    public static function listPath(): string
    {
        return resource_path('fonts/material-symbols.txt');
    }

    public static function fontPath(): string
    {
        return resource_path('fonts/material-symbols-outlined.woff2');
    }

    public static function checksumPath(): string
    {
        return resource_path('fonts/material-symbols.sha256');
    }
}
