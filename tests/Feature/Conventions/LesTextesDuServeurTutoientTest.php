<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\Vouvoiement;

/*
 * L'application tutoie (#1980, et le docbloc de `ConfirmDialog.vue`). Ses
 * écrans le font, mais des messages du serveur vouvoyaient encore, parfois
 * sur la page même qui tutoie (#2119) : les refus de la connexion par un
 * fournisseur (« Connectez-vous avec votre email et votre mot de passe »),
 * le statut de la réinitialisation (« Votre mot de passe a été
 * réinitialisé. »), la séance déjà en cours (« Terminez-la avant d'en
 * commencer une autre. ») et le refus d'un mot de passe paru dans une fuite
 * (« Veuillez choisir une valeur différente. »). Les courriels ont leur
 * propre garde, sur leur rendu (`CourrielsEnFrancaisTest`, #2118).
 *
 * La garde lit tout ce que le serveur peut montrer :
 * - chaque traduction française : `lang/fr.json`, `lang/fr/*.php` et
 *   `lang/vendor/{paquet}/fr/*.php`, que l'application lit tous ;
 * - chaque chaîne littérale du PHP de `app/` et de `database/seeders` (le
 *   catalogue des badges), commentaires exclus par le tokenizer, telle
 *   qu'elle s'affiche : échappements remplacés par ce qu'ils écrivent ;
 * - chaque vue de `resources/views`, commentaires Blade ôtés (pages
 *   d'erreur, gabarits des courriels) ;
 * - chaque texte du manifeste de l'application (`public/manifest.webmanifest`,
 *   versionné et servi tel quel), que le navigateur ou le système montre
 *   quand on installe l'application : sa description disait encore
 *   « Suivez vos entraînements et progressez efficacement. ».
 *
 * `lang/en` n'est lu que pour une clef que le français n'a pas, et il est
 * anglais. Un fichier de `lang/` qu'un paquet fournirait sans que
 * l'application le lise entrerait dans les exceptions, avec sa raison ; il
 * n'y en a aucun aujourd'hui.
 */

/**
 * Les chemins, relatifs à la racine, que la garde ne lit pas, et pourquoi.
 *
 * @return array<string, string>
 */
function tutoiementExceptions(): array
{
    $panneau = 'le panneau d’administration s’adresse à l’administrateur, pas à la personne qui s’entraîne, '
        .'et Filament y vouvoie dans ses propres textes (« Êtes-vous sûr(e) de vouloir faire ceci ? ») : '
        .'un texte du panneau suit son registre';

    return [
        'app/Filament/' => $panneau,
        'app/Providers/Filament/' => $panneau,
        'resources/views/filament/' => $panneau,
    ];
}

function tutoiementEstExempte(string $chemin): bool
{
    return array_any(array_keys(tutoiementExceptions()), static fn (string $prefixe): bool => str_starts_with($chemin, $prefixe));
}

/**
 * Le chemin d'un fichier, relatif à la racine du dépôt.
 */
function tutoiementCheminRelatif(string $chemin): string
{
    return str_replace(base_path().'/', '', $chemin);
}

/**
 * Chaque valeur d'une traduction, sous sa clef pointée.
 *
 * @param  array<array-key, mixed>  $traductions
 * @return array<string, string>
 */
function tutoiementValeurs(array $traductions, string $prefixe = ''): array
{
    $valeurs = [];

    foreach ($traductions as $clef => $valeur) {
        $chemin = $prefixe === '' ? (string) $clef : "{$prefixe}.{$clef}";

        if (is_array($valeur)) {
            $valeurs += tutoiementValeurs($valeur, $chemin);
        } elseif (is_string($valeur)) {
            $valeurs[$chemin] = $valeur;
        }
    }

    return $valeurs;
}

/**
 * Ce qu'affiche le source d'une chaîne entre guillemets doubles ou d'un
 * heredoc : chaque séquence d'échappement remplacée par ce qu'elle écrit,
 * comme PHP le fait. Lu tel quel, « \nVotre » collait le « n » de
 * l'échappement au mot, et le motif ne le reconnaissait plus. Un octet
 * échappé qui ne forme pas un caractère UTF-8 entier, ou un point de code
 * qui n'en est pas un, devient « ? ».
 */
function tutoiementSansEchappements(string $source): string
{
    $caracteres = ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f"];

    $texte = (string) preg_replace_callback(
        '/\\\\(?:([nrtvef\\\\$"])|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\}|([0-7]{1,3}))/',
        static function (array $echappement) use ($caracteres): string {
            if ($echappement[1] !== null) {
                return $caracteres[$echappement[1]] ?? $echappement[1];
            }

            if ($echappement[3] !== null) {
                $caractere = mb_chr((int) hexdec($echappement[3]), 'UTF-8');

                return $caractere === false ? '?' : $caractere;
            }

            $octet = $echappement[2] !== null ? hexdec($echappement[2]) : octdec((string) $echappement[4]);

            return chr((int) $octet & 0xFF);
        },
        $source,
        flags: PREG_UNMATCHED_AS_NULL,
    );

    return mb_scrub($texte, 'UTF-8');
}

/**
 * Chaque chaîne littérale d'un source PHP, telle qu'elle s'affiche, sous la
 * ligne où elle commence, commentaires exclus par le tokenizer. Les
 * échappements se lisent entre guillemets doubles et dans un heredoc, pas
 * entre apostrophes ni dans un nowdoc, où PHP les écrit tels quels. Les
 * chaînes d'une même ligne se suivent, séparées d'une espace.
 *
 * @return array<int, string>
 */
function tutoiementChainesDuPhp(string $source): array
{
    $chaines = [];
    $dansUnNowdoc = false;

    foreach (token_get_all($source) as $jeton) {
        if (! is_array($jeton)) {
            continue;
        }

        [$genre, $texte, $ligne] = $jeton;

        if ($genre === T_START_HEREDOC || $genre === T_END_HEREDOC) {
            $dansUnNowdoc = $genre === T_START_HEREDOC && str_contains($texte, "'");
        } elseif ($genre === T_CONSTANT_ENCAPSED_STRING || $genre === T_ENCAPSED_AND_WHITESPACE) {
            $echappementsLus = $genre === T_ENCAPSED_AND_WHITESPACE ? ! $dansUnNowdoc : str_ends_with($texte, '"');
            $affiche = $echappementsLus ? tutoiementSansEchappements($texte) : $texte;
            $chaines[$ligne] = isset($chaines[$ligne]) ? "{$chaines[$ligne]} {$affiche}" : $affiche;
        }
    }

    return $chaines;
}

/**
 * Chaque texte que le serveur peut montrer, sous l'endroit qui l'écrit :
 * « fichier « clef » » pour une traduction, « fichier:ligne » pour le code et
 * les vues.
 *
 * @return array<string, string>
 */
function tutoiementTextesDuServeur(): array
{
    $textes = [];

    /** @var array<string, string> $json */
    $json = json_decode((string) file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($json as $clefDuJson => $texteDuJson) {
        $textes["lang/fr.json « {$clefDuJson} »"] = $texteDuJson;
    }

    $fichiersDeLangue = glob(lang_path('fr/*.php'));
    $fichiersDesPaquets = glob(lang_path('vendor/*/fr/*.php'));
    $traductions = [
        ...(is_array($fichiersDeLangue) ? $fichiersDeLangue : []),
        ...(is_array($fichiersDesPaquets) ? $fichiersDesPaquets : []),
    ];

    foreach ($traductions as $fichier) {
        $contenu = require $fichier;
        expect($contenu)->toBeArray();
        assert(is_array($contenu));

        foreach (tutoiementValeurs($contenu) as $clef => $valeur) {
            $textes[tutoiementCheminRelatif($fichier)." « {$clef} »"] = $valeur;
        }
    }

    $php = Finder::create()->files()->in([app_path(), database_path('seeders')])->name('*.php');

    foreach ($php as $fichier) {
        $chemin = tutoiementCheminRelatif($fichier->getPathname());

        if (tutoiementEstExempte($chemin)) {
            continue;
        }

        foreach (tutoiementChainesDuPhp($fichier->getContents()) as $ligne => $chainesDeLaLigne) {
            $textes["{$chemin}:{$ligne}"] = $chainesDeLaLigne;
        }
    }

    $manifeste = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
    expect($manifeste)->toBeArray();
    assert(is_array($manifeste));

    foreach (tutoiementValeurs($manifeste) as $champ => $valeur) {
        $textes["public/manifest.webmanifest « {$champ} »"] = $valeur;
    }

    foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $fichier) {
        $chemin = tutoiementCheminRelatif($fichier->getPathname());

        if (tutoiementEstExempte($chemin)) {
            continue;
        }

        $sansCommentaires = (string) preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            static fn (array $commentaire): string => str_repeat("\n", substr_count($commentaire[0], "\n")),
            $fichier->getContents(),
        );

        foreach (explode("\n", $sansCommentaires) as $rang => $ligne) {
            $textes["{$chemin}:".($rang + 1)] = $ligne;
        }
    }

    return $textes;
}

it('tutoie dans chaque texte que le serveur peut montrer', function (): void {
    $fautes = [];

    foreach (tutoiementTextesDuServeur() as $endroit => $texte) {
        $mots = Vouvoiement::mots($texte);

        if ($mots !== []) {
            $fautes[] = "{$endroit} : ".implode(', ', $mots);
        }
    }

    expect($fautes)->toBe([], 'tutoyer, comme le reste de l’application (#1980) ; un texte qui s’adresse à un autre que la personne qui s’entraîne entre dans tutoiementExceptions(), avec sa raison');
});

it('lit les traductions, le code, les vues et le manifeste que la personne voit', function (): void {
    $textes = tutoiementTextesDuServeur();
    $endroits = implode("\n", array_keys($textes));

    // Sans ce qu'elle doit lire, la garde passerait sans rien vérifier.
    expect(count($textes))->toBeGreaterThan(2000)
        ->and($endroits)->toContain(
            'lang/fr.json « Your account must be verified before linking it with a social provider. »',
            'lang/fr/passwords.php « reset »',
            'lang/fr/validation.php « password.uncompromised »',
            'lang/vendor/health/fr/notifications.php « check_failed_mail_body »',
            'app/Actions/ResolveSocialUserAction.php:',
            'app/Http/Controllers/Api/WorkoutController.php:',
            'database/seeders/AchievementSeeder.php:',
            'resources/views/errors/404.blade.php:',
            'resources/views/vendor/notifications/email.blade.php:',
            'public/manifest.webmanifest « name »',
            'public/manifest.webmanifest « short_name »',
            'public/manifest.webmanifest « description »',
        )
        ->and($endroits)->not->toContain('app/Filament/', 'resources/views/filament/');
});

it('lit chaque chaîne du PHP telle qu’elle s’affiche, échappements compris', function (): void {
    $chaines = tutoiementChainesDuPhp(<<<'PHP'
        <?php
        $guillemets = "Séance enregistrée.\nVotre record tient toujours.";
        $heredoc = <<<TEXTE
            Bravo, {$nom} !\tContinuez ainsi.
            TEXTE;
        $unicode = "Pr\u{e9}f\u{e9}rez la barre, \x76os s\303\251ries.";
        $antislash = "Le chemin C:\\nVotre reste écrit tel quel.";
        $apostrophes = 'Le code \x56otre reste écrit tel quel.';
        $nowdoc = <<<'TEXTE'
            Le code \x56otre reste écrit tel quel.
            TEXTE;
        PHP);

    // Un antislash échappé n'échappe rien : « C:\nVotre » ne dit pas « Votre ».
    // Entre apostrophes et dans un nowdoc, rien ne s'échappe.
    expect(array_map(Vouvoiement::mots(...), $chaines))->toBe([
        2 => ['Votre'],
        4 => ['Continuez'],
        6 => ['Préférez', 'vos'],
        7 => [],
        8 => [],
        10 => [],
    ]);
});

it('n’exempte que des dossiers qui existent', function (): void {
    foreach (array_keys(tutoiementExceptions()) as $prefixe) {
        expect(base_path($prefixe))->toBeDirectory();
    }
});
