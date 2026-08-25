<?php

declare(strict_types=1);

/*
 * `ResolveSocialUserAction` etait traversee par les tests de rappel social sans
 * que ce qu'elle ECRIT soit relu : 10 de ses 26 mutants survivaient
 * (score 61,5 %).
 *
 * Ce que cela voulait dire concretement — chacune de ces reecritures passait la
 * suite au vert :
 *
 *  - lier un compte deja lie a un autre fournisseur, ou refuser de lier un
 *    compte dont l'identifiant de fournisseur est la chaine vide (ligne 32) ;
 *  - ne plus reprendre l'avatar au moment de la liaison (ligne 37) ;
 *  - ignorer le nom rendu par le fournisseur, ou ignorer son pseudonyme
 *    (ligne 46) ;
 *  - creer le compte SANS MOT DE PASSE (ligne 48) ;
 *  - creer le compte sans avatar (ligne 49) ;
 *  - creer le compte sans le marquer verifie (ligne 55) — c'est-a-dire laisser
 *    un compte qui, a la connexion suivante, se ferait refuser la liaison par
 *    le controle de securite de la ligne 25.
 *
 * Les valeurs comparees sont toutes POSEES : la fabrique d'utilisateur tire un
 * nom et une adresse au hasard, et l'horloge est arretee pour que la date de
 * verification soit une constante et non le resultat du meme `now()` que celui
 * du code teste.
 */

use App\Actions\ResolveSocialUserAction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as SocialiteUser;

use function Pest\Laravel\assertDatabaseHas;

/**
 * L'utilisateur rendu par le fournisseur, avec des valeurs posees.
 *
 * @param  array<string, string|null>  $attributs
 */
function utilisateurSocial(array $attributs = []): SocialiteUser
{
    $social = new SocialiteUser();
    $social->map([
        ...[
            'id' => 'google-42',
            'nickname' => 'jdup',
            'name' => 'Jean Dupont',
            'email' => 'jean@example.test',
            'avatar' => 'https://exemple.test/avatar-google.jpg',
        ],
        ...$attributs,
    ]);

    return $social;
}

function compteVerifieAvecFournisseur(?string $fournisseur, ?string $identifiant): User
{
    return User::factory()->create([
        'email' => 'jean@example.test',
        'email_verified_at' => Carbon::parse('2026-01-02 09:00:00'),
        'provider' => $fournisseur,
        'provider_id' => $identifiant,
        'avatar' => 'https://exemple.test/ancien-avatar.jpg',
    ]);
}

function resoudre(SocialiteUser $social): User
{
    return app(ResolveSocialUserAction::class)->execute('google', $social);
}

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
});

afterEach(function (): void {
    Str::createRandomStringsNormally();
});

it('lie un compte verifie dont la colonne fournisseur est nulle', function (): void {
    $existant = compteVerifieAvecFournisseur(null, null);

    $resolu = resoudre(utilisateurSocial());

    expect($resolu->id)->toBe($existant->id);

    $existant->refresh();

    expect($existant->provider)->toBe('google');
    expect($existant->provider_id)->toBe('google-42');

    // L'avatar fait partie de la liaison : sans cette ligne, le compte gardait
    // la photo d'un fournisseur qu'il n'utilise plus.
    expect($existant->avatar)->toBe('https://exemple.test/avatar-google.jpg');
});

it('lie un compte verifie dont la colonne fournisseur est la chaine vide', function (): void {
    /*
     * La chaine vide n'est pas un identifiant de fournisseur : c'est ce que
     * laisse une colonne remplie puis videe, ou un formulaire d'administration
     * enregistre sans valeur. Le code la traite comme « pas encore lie », et
     * rien ne le verifiait — remplacer cette chaine vide par n'importe quelle
     * autre laissait le compte orphelin sans que rien ne le signale.
     */
    $existant = compteVerifieAvecFournisseur('', '');

    resoudre(utilisateurSocial());

    $existant->refresh();

    expect($existant->provider)->toBe('google');
    expect($existant->provider_id)->toBe('google-42');
    expect($existant->avatar)->toBe('https://exemple.test/avatar-google.jpg');
});

it('ne touche pas au fournisseur d un compte deja lie', function (): void {
    /*
     * Le pendant, et la moitie qui compte pour la securite : une seconde
     * connexion — par un autre fournisseur, ou par le meme avec un autre
     * identifiant — ne doit pas reecrire la liaison en place, ni l'avatar.
     */
    $existant = compteVerifieAvecFournisseur('github', 'github-7');

    $resolu = resoudre(utilisateurSocial());

    expect($resolu->id)->toBe($existant->id);

    $existant->refresh();

    expect($existant->provider)->toBe('github');
    expect($existant->provider_id)->toBe('github-7');
    expect($existant->avatar)->toBe('https://exemple.test/ancien-avatar.jpg');
});

it('nomme le compte cree d apres le nom rendu par le fournisseur', function (): void {
    // Le fournisseur rend LES DEUX : c'est le nom qui doit gagner, et c'est la
    // seule facon de distinguer la premiere branche du `??` de la seconde.
    $nouveau = resoudre(utilisateurSocial());

    expect($nouveau->name)->toBe('Jean Dupont');
});

it('se replie sur le pseudonyme quand le fournisseur ne rend pas de nom', function (): void {
    $nouveau = resoudre(utilisateurSocial(['name' => null]));

    // « jdup » et non « Utilisateur » : sans cette assertion, sauter le
    // pseudonyme pour aller directement au repli generique passait.
    expect($nouveau->name)->toBe('jdup');
});

it('appelle « Utilisateur » un compte que le fournisseur ne sait pas nommer', function (): void {
    $nouveau = resoudre(utilisateurSocial(['name' => null, 'nickname' => null]));

    expect($nouveau->name)->toBe('Utilisateur');
});

it('inscrit l avatar, le fournisseur, la verification et un mot de passe sur le compte cree', function (): void {
    $nouveau = resoudre(utilisateurSocial())->refresh();

    expect($nouveau->email)->toBe('jean@example.test');
    expect($nouveau->avatar)->toBe('https://exemple.test/avatar-google.jpg');
    expect($nouveau->provider)->toBe('google');
    expect($nouveau->provider_id)->toBe('google-42');

    /*
     * La date figee, et pas « une date quelconque » : le compte est marque
     * verifie a l'instant de la creation. Sans cette assertion, le compte
     * naissait non verifie — et le controle de securite de la ligne 25 lui
     * refusait la liaison a la connexion suivante, donc l'utilisateur se
     * retrouvait enferme dehors.
     */
    assertDatabaseHas('users', [
        'id' => $nouveau->id,
        'email_verified_at' => '2026-06-15 12:00:00',
    ]);

    /*
     * La colonne `password` est NULLABLE : retirer la cle du `create()` ne
     * levait rien, elle laissait simplement un compte sans empreinte. Le
     * prefixe est celui de bcrypt, donc cette ligne dit a la fois « il y a un
     * mot de passe » et « il est hache ».
     */
    expect($nouveau->password)->toBeString()->toStartWith('$2y$');
});

it('tire le mot de passe jetable sur seize caracteres', function (): void {
    /*
     * L'empreinte bcrypt a une longueur fixe et le mot de passe en clair n'est
     * jamais rendu : depuis la base, un tirage de 15 ou de 17 caracteres est
     * indiscernable de 16. La seule facon de tenir cette longueur sans toucher
     * au code applicatif est d'instrumenter le tirage lui-meme, ce que Laravel
     * prevoit. La longueur d'un secret n'est pas un detail d'implementation :
     * c'est le parametre qui le rend inutilisable a qui le trouverait.
     */
    $longueursDemandees = [];

    Str::createRandomStringsUsing(function (int $longueur) use (&$longueursDemandees): string {
        $longueursDemandees[] = $longueur;

        return str_repeat('a', $longueur);
    });

    resoudre(utilisateurSocial());

    /*
     * `toContain` et non l'egalite : la creation du compte fait resoudre la
     * session, qui tire a son tour son identifiant sur 40 caracteres. Ce
     * second tirage n'appartient pas a cette action et son rang n'a pas a
     * etre fige ici.
     */
    expect($longueursDemandees)->toContain(16);
});
