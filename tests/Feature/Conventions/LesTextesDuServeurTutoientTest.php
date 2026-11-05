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
 *   catalogue des badges), commentaires exclus par le tokenizer ;
 * - chaque vue de `resources/views`, commentaires Blade ôtés (pages
 *   d'erreur, gabarits des courriels).
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
    foreach (array_keys(tutoiementExceptions()) as $prefixe) {
        if (str_starts_with($chemin, $prefixe)) {
            return true;
        }
    }

    return false;
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

    foreach ($json as $clef => $valeur) {
        $textes["lang/fr.json « {$clef} »"] = $valeur;
    }

    $traductions = [...(glob(lang_path('fr/*.php')) ?: []), ...(glob(lang_path('vendor/*/fr/*.php')) ?: [])];

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

        foreach (token_get_all($fichier->getContents()) as $jeton) {
            if (is_array($jeton) && in_array($jeton[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $endroit = "{$chemin}:{$jeton[2]}";
                $textes[$endroit] = isset($textes[$endroit]) ? "{$textes[$endroit]} {$jeton[1]}" : $jeton[1];
            }
        }
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

it('lit les traductions, le code et les vues que la personne voit', function (): void {
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
        )
        ->and($endroits)->not->toContain('app/Filament/', 'resources/views/filament/');
});

it('n’exempte que des dossiers qui existent', function (): void {
    foreach (array_keys(tutoiementExceptions()) as $prefixe) {
        expect(base_path($prefixe))->toBeDirectory();
    }
});
