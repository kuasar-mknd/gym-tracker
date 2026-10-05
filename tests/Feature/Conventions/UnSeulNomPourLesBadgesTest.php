<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\User;
use App\Notifications\AchievementUnlocked;
use Symfony\Component\Finder\Finder;

/*
 * Les succès portaient quatre noms : la page s'intitulait « Succès & Badges »
 * puis « Trophées 🏆 », le menu disait « Trophées », la notification « Succès
 * Déverrouillé ! », et `lang/fr.json` traduisait « Achievements » par
 * « Honneurs » (#1980).
 *
 * Le nom retenu est celui que l'interface employait le plus, à la date du
 * correctif : « Badge » revenait sept fois (onglet, en-tête et état vide de la
 * page, titre de la fenêtre de célébration, libellé, pluriel et menu du
 * panneau), « Succès » six (onglet et en-tête de la page, titre, action et
 * message de la notification), « Trophées » trois (menu du profil, menu du
 * compte, titre de la page), « Honneurs » aucune (une traduction que rien
 * n'affichait). Le propriétaire du dépôt peut en choisir un autre : la garde
 * se change alors avec lui.
 *
 * Elle lit le texte que voient les gens : les composants Vue (commentaires
 * ôtés, puisque le menu du profil vit dans le script), les chaînes des
 * notifications et de la ressource du panneau, et les traductions. « avec succès » (une réussite, pas un badge) reste permis.
 */

/**
 * Les autres noms donnés aux badges, tels qu'on les lit dans une phrase.
 */
function unSeulNomAutresNoms(): string
{
    return '/\b(troph[ée]es?|honneurs?)\b|(?-i:\bSuccès\b)|\b(le|les|mes|tes|ses|un|des|du|nouveau|ton|mon) succès\b/iu';
}

/**
 * Les traductions françaises de `lang/fr.json`.
 *
 * @return array<string, string>
 */
function unSeulNomTraductions(): array
{
    /** @var array<string, string> $traductions */
    $traductions = json_decode((string) file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);

    return $traductions;
}

/**
 * Le texte qu'affiche chaque fichier lu, avec son chemin.
 *
 * @return array<string, string>
 */
function unSeulNomTextesAffiches(): array
{
    $textes = [];

    foreach (Finder::create()->files()->in(resource_path('js'))->name('*.vue') as $fichier) {
        $textes['resources/js/'.$fichier->getRelativePathname()] = (string) preg_replace(
            ['/<!--.*?-->/s', '#/\*.*?\*/#s', '#(^|\s)//[^\n]*#'],
            ['', '', '$1'],
            $fichier->getContents(),
        );
    }

    $notifications = glob(app_path('Notifications/*.php'));
    $chemins = [
        ...(is_array($notifications) ? $notifications : []),
        ...array_map(
            static fn (SplFileInfo $fichier): string => $fichier->getPathname(),
            iterator_to_array(Finder::create()->files()->in(app_path('Filament/Resources/Achievements'))->name('*.php'), false),
        ),
    ];

    foreach ($chemins as $chemin) {
        $chaines = '';

        foreach (token_get_all((string) file_get_contents($chemin)) as $jeton) {
            if (is_array($jeton) && in_array($jeton[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $chaines .= ' '.$jeton[1];
            }
        }

        $textes[str_replace(base_path().'/', '', $chemin)] = $chaines;
    }

    $textes['lang/fr.json'] = implode("\n", unSeulNomTraductions());

    return $textes;
}

it('ne donne aux badges qu’un nom dans l’interface', function (): void {
    $textes = unSeulNomTextesAffiches();

    expect(count($textes))->toBeGreaterThan(50);

    $autresNoms = array_keys(array_filter(
        $textes,
        static fn (string $texte): bool => preg_match(unSeulNomAutresNoms(), $texte) === 1,
    ));

    expect($autresNoms)->toBe([], 'ces fichiers nomment encore les badges « Trophées », « Succès » ou « Honneurs »');
});

it('appelle « Badges » la page, les deux menus, la traduction et la notification', function (): void {
    $textes = unSeulNomTextesAffiches();

    expect($textes['resources/js/Pages/Achievements/Index.vue'])->toContain('title="Badges"', 'page-title="Badges"', 'Badges 🏆')
        ->and($textes['resources/js/Pages/Profile/Index.vue'])->toContain("name: 'Badges'")
        ->and($textes['resources/js/Layouts/AuthenticatedLayout.vue'])->toMatch('/>\s*Badges\s*</u')
        ->and(unSeulNomTraductions()['Achievements'] ?? null)->toBe('Badges');

    $notification = new AchievementUnlocked(Achievement::factory()->make(['name' => 'Marathonien du Fer']));
    $donnees = $notification->toArray(User::factory()->make());

    expect($donnees['title'])->toBe('Badge débloqué ! 🏆')
        ->and($donnees['message'])->toBe('Félicitations ! Tu as débloqué le badge : Marathonien du Fer.');
});

it('reconnaît les autres noms, et laisse passer une réussite « avec succès »', function (): void {
    foreach (['Trophées 🏆', 'Succès & Badges', 'Voir mes succès', 'Honneurs', 'le succès : X', 'Nouveau trophée'] as $autreNom) {
        expect(preg_match(unSeulNomAutresNoms(), $autreNom))->toBe(1, $autreNom);
    }

    expect(preg_match(unSeulNomAutresNoms(), 'Objectif créé avec succès.'))->toBe(0);
});
