<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use App\Support\AvatarDesInitiales;
use App\Support\Charte;
use App\Support\ComptesVusParPulse;
use Filament\Facades\Filament;
use Laravel\Pulse\Contracts\ResolvesUsers;
use Laravel\Pulse\Facades\Pulse;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

/**
 * L'avatar par défaut du panneau et ceux de la carte « Usage » de Pulse sont
 * dessinés par l'application, en SVG servi en `data:`. Filament prenait le sien
 * chez un service tiers, à qui chaque page du panneau envoyait le nom de
 * l'administrateur ; Pulse prenait les siens chez d'autres, d'après chaque
 * compte. Le nom n'entre dans le dessin que par ses initiales, des lettres :
 * rien de ce qu'il porte d'autre ne peut y écrire du SVG.
 */
function avatarSansTiersSvg(string $adresse): string
{
    expect($adresse)->toStartWith('data:image/svg+xml;base64,');

    $svg = base64_decode(substr($adresse, strlen('data:image/svg+xml;base64,')), true);

    expect($svg)->toBeString();

    return (string) $svg;
}

/**
 * Le SVG lu comme le lirait le navigateur : un document XML bien formé, dont
 * on rend le texte et le nom de chaque élément.
 *
 * @return array{texte: string, elements: list<string>}
 */
function avatarSansTiersLu(string $svg): array
{
    $document = new DOMDocument();

    expect($document->loadXML($svg, LIBXML_NONET))->toBeTrue();

    $elements = [];

    foreach ($document->getElementsByTagName('*') as $element) {
        $elements[] = $element->nodeName;
    }

    return ['texte' => (string) $document->documentElement?->textContent, 'elements' => $elements];
}

function avatarSansTiersSuperAdministrateur(string $nom): Admin
{
    $administrateur = Admin::factory()->create(['name' => $nom]);
    $administrateur->assignRole(Role::findOrCreate('super_admin', 'admin'));

    return $administrateur->fresh() ?? $administrateur;
}

it('tire les initiales du premier et du dernier mot, en capitales, sans rien d’autre que des lettres', function (string $nom, string $attendues): void {
    expect(AvatarDesInitiales::initiales($nom))->toBe($attendues);
})->with([
    'prénom et nom' => ['Camille Martin', 'CM'],
    'trois mots' => ['Jean de la Fontaine', 'JF'],
    'un seul mot' => ['camille', 'C'],
    'nom composé' => ['Jean-Pierre Dupont', 'JD'],
    'apostrophe' => ['Marie O’Brien', 'MO'],
    'ponctuation en tête' => ['[SYSTÈME] Admin', 'SA'],
    'accents' => ['élodie Ïmbert', 'ÉÏ'],
    'espaces autour' => ["  \t camille   martin \n", 'CM'],
    'chiffres sautés' => ['007 Bond', 'B'],
    'balises' => ['</text><script>alert(1)</script>', 'T'],
    'guillemets et esperluette' => ['"&amp;" <b>', 'AB'],
    'vide' => ['', ''],
    'emojis' => ['😀 🎉', ''],
    'chiffres seuls' => ['42 1337', ''],
    'UTF-8 invalide' => ["\xff\xfe Martin", ''],
]);

/**
 * @param  list<string>  $elements
 */
it('dessine un SVG bien formé, sans autre élément que le fond et les initiales, quel que soit le nom', function (string $nom, string $texte, array $elements): void {
    $lu = avatarSansTiersLu(avatarSansTiersSvg(new AvatarDesInitiales()->pourLeNom($nom)));

    expect($lu['texte'])->toBe($texte)
        ->and($lu['elements'])->toBe($elements);
})->with([
    'nom ordinaire' => ['Camille Martin', 'CM', ['svg', 'rect', 'text']],
    'balise de fermeture puis script' => ['</text><script>alert(document.cookie)</script>', 'T', ['svg', 'rect', 'text']],
    'élément SVG' => ['<svg onload=alert(1)><foreignObject>x</foreignObject>', 'SO', ['svg', 'rect', 'text']],
    'entité' => ['&lt;script&gt; ]]> <!--', 'L', ['svg', 'rect', 'text']],
    'vide : une silhouette' => ['', '', ['svg', 'rect', 'circle', 'path']],
    'emojis : une silhouette' => ['🏋️ 💪', '', ['svg', 'rect', 'circle', 'path']],
]);

it('peint le fond et le texte de l’utilitaire apparié accent-fill de la charte', function (): void {
    $surface = Charte::surfacesAppariees()['accent-fill'];
    $svg = avatarSansTiersSvg(new AvatarDesInitiales()->pourLeNom('Camille Martin'));

    expect($svg)->toContain('<rect width="64" height="64" fill="'.Charte::jeton($surface['fond']).'"/>')
        ->toContain('fill="'.Charte::jeton($surface['texte']).'">CM</text>');
});

it('est l’avatar par défaut du panneau, et l’administrateur le reçoit sans requête vers un tiers', function (): void {
    $administrateur = avatarSansTiersSuperAdministrateur('Camille </text><script>alert(1)</script> Martin');

    $corps = (string) actingAs($administrateur, 'admin')->get('/backoffice')->assertOk()->getContent();

    expect(Filament::getPanel('admin')->getDefaultAvatarProvider())->toBe(AvatarDesInitiales::class)
        ->and(preg_match('/<img\b[^>]*\bfi-user-avatar\b[^>]*>/', $corps, $avatar))->toBe(1)
        ->and($avatar[0] ?? '')->toContain('src="'.new AvatarDesInitiales()->pourLeNom('Camille </text><script>alert(1)</script> Martin').'"')
        ->and($corps)->not->toContain('ui-avatars')
        ->and($corps)->not->toContain('<script>alert(1)</script>');
});

/**
 * Pulse résout les comptes de sa carte « Usage » par le résolveur lié au
 * conteneur : celui de l'application, quel que soit l'ordre des fournisseurs.
 * Un compte retrouvé garde son nom et son adresse ; un identifiant que Pulse
 * ne retrouve pas reçoit une silhouette, là où Pulse mettait l'image par défaut
 * du service d'avatars.
 */
it('donne à Pulse le nom, l’adresse et un avatar dessiné de chaque compte, jamais celui d’un tiers', function (): void {
    $compte = User::factory()->create([
        'name' => 'Camille Martin',
        'email' => 'camille.martin@example.org',
        'avatar' => 'https://avatars.example.org/camille.png',
    ]);
    $inconnu = $compte->id + 1000;

    $comptes = Pulse::resolveUsers(collect([$compte->id, $inconnu]));
    $vu = $comptes->find($compte->id);
    $perdu = $comptes->find($inconnu);

    expect(app(ResolvesUsers::class))->toBeInstanceOf(ComptesVusParPulse::class)
        ->and((array) $vu)->toBe([
            'name' => 'Camille Martin',
            'extra' => 'camille.martin@example.org',
            'avatar' => new AvatarDesInitiales()->pourLeNom('Camille Martin'),
        ])
        ->and((array) $perdu)->toBe([
            'name' => 'ID: '.$inconnu,
            'extra' => '',
            'avatar' => new AvatarDesInitiales()->pourLeNom(''),
        ]);
});
