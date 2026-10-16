<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use App\Providers\TelescopeServiceProvider;
use App\Support\AvatarDesInitiales;
use App\Support\Charte;
use App\Support\ComptesVusParPulse;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Laravel\Pulse\Contracts\ResolvesUsers;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Value;
use Laravel\Telescope\Avatar;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\Http\Controllers\RequestsController;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * L'avatar par défaut du panneau, ceux de la carte « Usage » de Pulse et celui
 * du détail d'une entrée de Telescope sont dessinés par l'application, en SVG
 * servi en `data:`. Filament prenait le sien chez un service tiers, à qui
 * chaque page du panneau envoyait les initiales du nom de l'administrateur ;
 * Pulse et Telescope prenaient les leurs chez d'autres, d'après chaque compte.
 * Le nom n'entre dans le dessin que par ses initiales, des lettres : rien de ce
 * qu'il porte d'autre ne peut y écrire du SVG.
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

/**
 * La carte « Usage » telle que le tableau de bord de Pulse la charge : après la
 * page, par la requête Livewire (`__lazyLoad`) qui rejoue la pile de Pulse, où
 * le middleware du panneau fait de `admin` la garde par défaut.
 */
function avatarSansTiersCarteUsage(Admin $administrateur): string
{
    $page = (string) actingAs($administrateur, 'admin')->get('/backoffice/pulse')->assertOk()->getContent();

    preg_match_all('/wire:snapshot="([^"]*)"[^>]*x-intersect="\$wire\.__lazyLoad\(&#039;([^&]+)&#039;\)"/', $page, $cartes, PREG_SET_ORDER);

    foreach ($cartes as $carte) {
        $instantane = html_entity_decode($carte[1], ENT_QUOTES);

        if (! str_contains($instantane, '"name":"pulse.usage"')) {
            continue;
        }

        $adresse = app(HandleRequests::class)->getUpdateUri();

        if (! is_string($adresse)) {
            throw new RuntimeException('Livewire ne donne pas l’adresse de ses mises à jour.');
        }

        $html = postJson($adresse, [
            'components' => [[
                'snapshot' => $instantane,
                'updates' => [],
                'calls' => [['method' => '__lazyLoad', 'params' => [$carte[2]], 'metadata' => []]],
            ]],
        ], ['X-Livewire' => '1'])->assertOk()->json('components.0.effects.html');

        if (! is_string($html)) {
            throw new RuntimeException('La carte « Usage » ne s’est pas rendue.');
        }

        expect($html)->toContain('wire:name="pulse.usage"');

        return $html;
    }

    throw new RuntimeException('Aucune carte « Usage » paresseuse dans la page de Pulse.');
}

/**
 * Un compte de l'application et un administrateur portent des numéros tirés de
 * deux tables : le même numéro peut désigner l'un et l'autre. Pulse compte les
 * requêtes du panneau sous l'administrateur, celles de l'application sous le
 * compte, et la carte se charge sous la garde du panneau : elle cherchait
 * chaque numéro parmi les administrateurs, et montrait l'administrateur à la
 * place du compte.
 */
it('montre dans la carte « Usage » le compte de l’application et l’administrateur pour ce qu’ils sont, même de même numéro', function (): void {
    $administrateur = Admin::factory()->create(['name' => 'Alice Administratrice', 'email' => 'alice@example.org']);
    $administrateur->assignRole(Role::findOrCreate('super_admin', 'admin'));
    $usager = User::factory()->create(['id' => $administrateur->id, 'name' => 'Ulysse Usager', 'email' => 'ulysse@example.org']);
    $resolveur = app(ResolvesUsers::class);
    $maintenant = now()->getTimestamp();

    /** @var list<Entry|Value> $requetes trois requêtes du compte, deux de l'administrateur */
    $requetes = [
        ...array_map(static fn (int $rang): Entry => new Entry($maintenant - $rang, 'user_request', (string) $resolveur->key($usager))->count(), [1, 2, 3]),
        ...array_map(static fn (int $rang): Entry => new Entry($maintenant - $rang, 'user_request', (string) $resolveur->key($administrateur))->count(), [1, 2]),
    ];
    app(Storage::class)->store(collect($requetes));

    $carte = avatarSansTiersCarteUsage($administrateur->fresh() ?? $administrateur);

    expect($carte)->toContain('Ulysse Usager', 'ulysse@example.org', 'src="'.new AvatarDesInitiales()->pourLeNom('Ulysse Usager').'"')
        ->toContain('Alice Administratrice', 'Administrateur · alice@example.org', 'src="'.new AvatarDesInitiales()->pourLeNom('Alice Administratrice').'"');
    expect($carte)->not->toContain('ID: ');
});

/**
 * Pulse lit la clé d'un compte à la fin de chaque requête
 * (`Pulse::resolveAuthenticatedUserId()`, dans `UserRequests`), sous la garde
 * par défaut de la requête : celle du panneau pour une page du panneau, `web`
 * pour une page de l'application. `actingAs()` sans garde reprendrait la garde
 * par défaut que le premier `actingAs($administrateur, 'admin')` a laissée,
 * `admin` : le compte est donc connecté sous `web`, nommément, et le test
 * relève la garde de chaque requête.
 */
it('compte une requête du panneau sous la clé de l’administrateur, une requête de l’application sous le numéro du compte', function (): void {
    $administrateur = avatarSansTiersSuperAdministrateur('Alice Administratrice');
    $usager = User::factory()->create(['id' => $administrateur->id]);
    $cles = [];
    app()->terminating(static function () use (&$cles): void {
        $cles[] = [auth()->getDefaultDriver(), Pulse::resolveAuthenticatedUserId()];
    });

    actingAs($administrateur, 'admin')->get('/backoffice')->assertOk();
    $this->withoutVite();
    actingAs($usager, 'web')->get('/raccourcis')->assertOk();

    expect($cles)->toBe([['admin', 'admin:'.$administrateur->id], ['web', $usager->id]]);
});

/**
 * Le fournisseur de Telescope de l'application, enregistré comme en local, le
 * temps de l'essai. Telescope range ses réglages, rappel de l'avatar compris,
 * dans des propriétés statiques : elles reprennent ensuite leur valeur, pour
 * les tests suivants.
 *
 * @template T
 *
 * @param  Closure(): T  $essai
 * @return T
 */
function avatarSansTiersSousTelescope(Closure $essai): mixed
{
    $proprietes = array_values(array_filter(
        [...new ReflectionClass(Telescope::class)->getProperties(), ...new ReflectionClass(Avatar::class)->getProperties()],
        static fn (ReflectionProperty $propriete): bool => $propriete->isStatic(),
    ));
    $valeurs = array_map(static fn (ReflectionProperty $propriete): mixed => $propriete->getValue(), $proprietes);

    config(['telescope.enabled' => true]);
    new TelescopeServiceProvider(app())->register();

    try {
        return $essai();
    } finally {
        foreach ($proprietes as $rang => $propriete) {
            $propriete->setValue(null, $valeurs[$rang]);
        }
    }
}

/**
 * Telescope montre, dans le détail d'une entrée, l'avatar du compte qui l'a
 * faite. Sans réglage, il le prenait chez un service d'avatars tiers, d'après
 * l'empreinte de son adresse. Il ne passe au rappel que le numéro et
 * l'adresse : le numéro seul désignerait aussi l'administrateur de même
 * numéro.
 */
it('donne à Telescope l’avatar dessiné du compte de chaque entrée, pris dans la bonne table, jamais celui d’un tiers', function (): void {
    $usager = User::factory()->create(['name' => 'Camille Martin', 'email' => 'camille.martin@example.org']);
    $administrateur = Admin::factory()->create(['id' => $usager->id, 'name' => 'Alice Administratrice', 'email' => 'alice@example.org']);
    $inconnu = User::factory()->make(['id' => $usager->id + 1000, 'name' => 'Fantôme', 'email' => 'fantome@example.org']);

    $avatars = avatarSansTiersSousTelescope(static function () use ($usager, $administrateur, $inconnu): array {
        $lot = (string) Str::orderedUuid();
        $entrees = array_map(
            static fn (Authenticatable $compte): IncomingEntry => IncomingEntry::make(['uri' => '/raccourcis'])->batchId($lot)->type(EntryType::REQUEST)->user($compte),
            [$usager, $administrateur, $inconnu],
        );
        app(EntriesRepository::class)->store(collect($entrees));

        return array_map(
            static function (IncomingEntry $entree): mixed {
                $reponse = app()->call([app(RequestsController::class), 'show'], ['id' => $entree->uuid]);

                return $reponse instanceof JsonResponse ? data_get($reponse->getData(true), 'entry.content.user.avatar') : $reponse;
            },
            $entrees,
        );
    });

    expect($avatars)->toBe([
        new AvatarDesInitiales()->pourLeNom('Camille Martin'),
        new AvatarDesInitiales()->pourLeNom('Alice Administratrice'),
        new AvatarDesInitiales()->pourLeNom(''),
    ]);
});
