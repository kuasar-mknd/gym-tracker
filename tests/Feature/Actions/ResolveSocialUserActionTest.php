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
use App\Exceptions\SocialAuthException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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

function resoudre(SocialiteUser $social, bool $adresseVerifiee = true): User
{
    return app(ResolveSocialUserAction::class)->execute('google', $social, $adresseVerifiee);
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

    // Posée sur l'adresse exacte et garantie, la liaison est prouvée.
    expect($existant->liaison_prouvee_le?->toDateTimeString())->toBe('2026-06-15 12:00:00');
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
    expect($existant->liaison_prouvee_le)->toBeNull();
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
        'liaison_prouvee_le' => '2026-06-15 12:00:00',
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

it('refuse un retour sans identifiant de fournisseur', function (?string $identifiant): void {
    /*
     * Sans identifiant, la liaison ne pourrait rien enregistrer, et la
     * connexion suivante ne reconnaîtrait pas le compte : elle repasserait par
     * l'adresse. La chaîne vide n'en est pas un non plus.
     *
     * Le message est comparé en entier : `toThrow(classe, message)` ne
     * vérifie que la présence du texte.
     */
    $existant = compteVerifieAvecFournisseur(null, null);

    expect(fn (): User => resoudre(utilisateurSocial(['id' => $identifiant])))
        ->toThrow(new SocialAuthException('Erreur lors de la connexion avec Google'));

    expect($existant->refresh()->provider)->toBeNull();
    expect($existant->provider_id)->toBeNull();
    expect(User::query()->count())->toBe(1);
})->with([
    'absent' => [null],
    'vide' => [''],
]);

it('refuse un retour sans adresse au lieu d’échouer à l’écriture', function (?string $adresse): void {
    expect(fn (): User => resoudre(utilisateurSocial(['email' => $adresse])))
        ->toThrow(new SocialAuthException('Google ne nous a transmis aucune adresse email. Connectez-vous avec votre email et votre mot de passe, ou inscrivez-vous.'));

    expect(User::query()->count())->toBe(0);
})->with([
    'absente' => [null],
    'vide' => [''],
]);

it('crée un compte non vérifié quand le fournisseur ne garantit pas l’adresse', function (): void {
    /*
     * Le seul chemin qui y mène est le contournement local du contrôle de
     * vérification. Marqué vérifié, ce compte se serait ensuite laissé
     * rattacher à d'autres fournisseurs sur la foi d'une adresse que personne
     * n'a confirmée.
     */
    $nouveau = resoudre(utilisateurSocial(), adresseVerifiee: false)->refresh();

    expect($nouveau->provider_id)->toBe('google-42');
    expect($nouveau->hasVerifiedEmail())->toBeFalse();

    // Rien ne dit que l'identité détient l'adresse : sa liaison reste sans
    // preuve, et n'ouvre le compte que pour cette adresse exacte.
    expect($nouveau->liaison_prouvee_le)->toBeNull();

    expect(resoudre(utilisateurSocial(), adresseVerifiee: false)->id)->toBe($nouveau->id);
    expect(fn (): User => resoudre(utilisateurSocial(['email' => 'nouvelle@example.test']), adresseVerifiee: false))
        ->toThrow(new SocialAuthException('Ce compte Google est associé à un compte dont l\'adresse email n\'est pas celle que Google nous transmet. Connectez-vous avec l\'adresse email de ce compte et votre mot de passe. Si vous n\'en avez pas, « Mot de passe oublié ? » vous permet d\'en choisir un.'));
    expect(User::query()->count())->toBe(1);
});

it('départage deux comptes de la même identité par l’adresse exacte, et refuse sinon', function (): void {
    /*
     * L'ancienne recherche par adresse créait un second compte pour la même
     * identité quand l'adresse changeait chez le fournisseur. Ces doublons
     * peuvent exister : le retour va au compte de l'adresse exacte, jamais au
     * premier venu, et à aucun quand ni l'un ni l'autre ne l'a.
     */
    $ancien = User::factory()->create(['email' => 'ancienne@example.test', 'provider' => 'google', 'provider_id' => 'google-42']);
    $recent = User::factory()->create(['email' => 'jean@example.test', 'provider' => 'google', 'provider_id' => 'google-42']);

    expect(resoudre(utilisateurSocial())->id)->toBe($recent->id);
    expect(resoudre(utilisateurSocial(['email' => 'Ancienne@Example.test']))->id)->toBe($ancien->id);

    $journal = Log::spy();

    expect(fn (): User => resoudre(utilisateurSocial(['email' => 'autre@example.test'])))
        ->toThrow(new SocialAuthException('Ce compte Google est associé à un compte dont l\'adresse email n\'est pas celle que Google nous transmet. Connectez-vous avec l\'adresse email de ce compte et votre mot de passe. Si vous n\'en avez pas, « Mot de passe oublié ? » vous permet d\'en choisir un.'));

    expect(User::query()->count())->toBe(2);

    // Les comptes en cause, dans l'ordre de leur création, et jamais l'adresse.
    $journal->shouldHaveReceived('warning')
        ->once()
        ->withArgs(static fn (string $message, array $contexte): bool => $message === 'Connexion sociale refusée : l’identité rend une autre adresse que celle de son compte'
            && $contexte === ['fournisseur' => 'google', 'comptes' => [$ancien->id, $recent->id]]);
});

it('ne prend pas pour sienne une identité qui ne diffère que par la casse', function (): void {
    /*
     * La colonne `provider_id` a la collation de la table : la base rend
     * « GOOGLE-42 » pour « google-42 ». Le filtre exact est fait en PHP, et le
     * compte, lié à une autre identité de Google, n'est pas rattaché non plus
     * par son adresse.
     */
    $existant = compteVerifieAvecFournisseur('google', 'GOOGLE-42');

    expect(fn (): User => resoudre(utilisateurSocial()))
        ->toThrow(new SocialAuthException('Un compte existe déjà avec cette adresse email, associé à un autre compte Google. Connectez-vous avec cette adresse et votre mot de passe. Si vous n\'en avez pas, « Mot de passe oublié ? » vous permet d\'en choisir un.'));

    expect($existant->refresh()->provider_id)->toBe('GOOGLE-42');
    expect(User::query()->count())->toBe(1);
});

it('ouvre le seul compte de l’identité dont la liaison est prouvée quand aucun n’a l’adresse rendue, et refuse quand ils sont plusieurs', function (): void {
    /*
     * Un doublon d'avant la preuve peut porter la même identité. Seule la
     * liaison prouvée dit lequel des comptes l'identité a ouvert sur son
     * adresse exacte : sans adresse commune, le retour lui revient, et à aucun
     * quand deux comptes la portent.
     */
    $sansPreuve = User::factory()->create(['email' => 'ancienne@example.test', 'provider' => 'google', 'provider_id' => 'google-42']);
    $prouve = User::factory()->create([
        'email' => 'jean@example.test',
        'provider' => 'google',
        'provider_id' => 'google-42',
        'liaison_prouvee_le' => Carbon::parse('2026-03-01 10:00:00'),
    ]);

    expect(resoudre(utilisateurSocial(['email' => 'nouvelle@example.test']))->id)->toBe($prouve->id);
    expect($sansPreuve->refresh()->liaison_prouvee_le)->toBeNull();

    // L'adresse exacte du compte sans preuve l'ouvre, et la prouve.
    expect(resoudre(utilisateurSocial(['email' => 'ancienne@example.test']))->id)->toBe($sansPreuve->id);
    expect($sansPreuve->refresh()->liaison_prouvee_le?->toDateTimeString())->toBe('2026-06-15 12:00:00');

    $journal = Log::spy();

    expect(fn (): User => resoudre(utilisateurSocial(['email' => 'nouvelle@example.test'])))
        ->toThrow(new SocialAuthException('Ce compte Google est associé à un compte dont l\'adresse email n\'est pas celle que Google nous transmet. Connectez-vous avec l\'adresse email de ce compte et votre mot de passe. Si vous n\'en avez pas, « Mot de passe oublié ? » vous permet d\'en choisir un.'));

    $journal->shouldHaveReceived('warning')
        ->once()
        ->withArgs(static fn (string $message, array $contexte): bool => $message === 'Connexion sociale refusée : l’identité rend une autre adresse que celle de son compte'
            && $contexte === ['fournisseur' => 'google', 'comptes' => [$sansPreuve->id, $prouve->id]]);
});

it('garde la date d’une preuve déjà posée', function (): void {
    $compte = compteVerifieAvecFournisseur('google', 'google-42');
    $compte->forceFill(['liaison_prouvee_le' => Carbon::parse('2026-03-01 10:00:00')])->save();

    expect(resoudre(utilisateurSocial())->id)->toBe($compte->id);
    expect($compte->refresh()->liaison_prouvee_le?->toDateTimeString())->toBe('2026-03-01 10:00:00');
});

it('ne prouve pas une liaison sur une adresse que le fournisseur ne garantit pas', function (): void {
    /*
     * Seul le contournement local y mène. L'identité ouvre son compte pour
     * l'adresse exacte, mais sans garantie, ce retour ne prouve rien : la
     * liaison n'ouvrira pas le compte pour une autre adresse.
     */
    $compte = User::factory()->unverified()->create(['email' => 'jean@example.test', 'provider' => 'google', 'provider_id' => 'google-42']);

    expect(resoudre(utilisateurSocial(), adresseVerifiee: false)->id)->toBe($compte->id);
    expect($compte->refresh()->liaison_prouvee_le)->toBeNull();
    expect($compte->hasVerifiedEmail())->toBeFalse();

    expect(fn (): User => resoudre(utilisateurSocial(['email' => 'nouvelle@example.test']), adresseVerifiee: false))
        ->toThrow(SocialAuthException::class);
});
