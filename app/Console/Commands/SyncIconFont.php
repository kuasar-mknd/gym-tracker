<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Reconstruit le sous-ensemble Material Symbols auto-hébergé à partir de la
 * liste d'icônes.
 *
 * Le sous-ensemble ne porte que les icônes que l'application dessine — environ
 * 10 Kio contre les 1 099 Kio de la fonte variable qu'elle allait chercher chez
 * Google à chaque démarrage à froid. L'économie vaut d'être prise, mais elle a
 * ouvert une façon d'avoir tort en silence : la liste est l'intention, le
 * .woff2 est l'artefact, et rien ne les liait. Une icône ajoutée à la liste
 * sans régénérer la fonte s'affiche dans l'interface sous la forme de son
 * propre nom en toutes lettres.
 *
 * Cette commande écrit donc la fonte ET une empreinte de la liste qui l'a
 * produite, et IconSubsetTest échoue quand les deux divergent. Régénérer tient
 * désormais en une commande plutôt qu'en une incantation curl cachée dans un
 * commentaire.
 */
class SyncIconFont extends Command
{
    #[\Override]
    protected $signature = 'icons:sync {--check : Verify the font matches the list without fetching}';

    #[\Override]
    protected $description = 'Rebuild the self-hosted Material Symbols subset from resources/fonts/material-symbols.txt';

    /**
     * Google ne sert le woff2 qu'aux navigateurs qu'il croit capables de le
     * lire ; la chaîne cliente par défaut rapporte un ttf, que la règle
     * `@font-face` n'arrive alors pas à charger.
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

        // L'URL ne finit plus par .woff2 — Google sert désormais les
        // sous-ensembles depuis /l/font?kit=…, donc le format se lit dans la
        // règle et non dans le chemin.
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

        // sort() réindexe, donc ce qui revient est déjà une liste.
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
