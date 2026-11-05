<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\AdresseCanonique;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Mockery\MockInterface;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\post;

/*
 * Une adresse de compte enregistrée en Unicode avant #2001 n'était trouvée
 * que sous cette forme, et la recherche écrit désormais toute saisie sous sa
 * forme canonique, en ASCII (#2168) : sans la migration, un tel compte ne se
 * trouverait plus sous aucune forme.
 * `2026_11_05_134929_ecrire_en_ascii_les_domaines_internationalises_des_comptes.php`
 * écrit donc en ASCII, par `AdresseCanonique` et le constructeur de requêtes,
 * chaque adresse dont la forme ASCII n'est portée par aucun autre compte, ni
 * partagée avec une autre adresse en Unicode, selon la base : la collation de
 * `users.email`, pas une égalité en PHP. La boîte ne change pas : ni avis, ni
 * vérification retirée, ni preuve de liaison effacée. Rien n'est fusionné ni
 * supprimé, et aucun compte n'est choisi à la place d'un autre : chaque
 * adresse laissée, et chaque compte de sa forme, se liste par identifiant,
 * sans adresse, pour que le propriétaire en décide. La migration n'écrit
 * aucun schéma : elle se joue dans la transaction du test.
 */

/**
 * Joue `up()` de la migration, comme `migrate`.
 */
function domainesEnAsciiMigrer(): void
{
    $migration = require database_path('migrations/2026_11_05_134929_ecrire_en_ascii_les_domaines_internationalises_des_comptes.php');
    assert($migration instanceof Migration && method_exists($migration, 'up'));

    $migration->up();
}

/**
 * Un journal espion, qui garde chaque trace écrite pendant la migration.
 */
function domainesEnAsciiJournal(): MockInterface
{
    return Log::spy();
}

/**
 * La trace écrite une fois, avec ce message et ce contexte exactement : des
 * identifiants et des états, jamais une adresse.
 *
 * @param  array<string, mixed>  $contexte
 */
function domainesEnAsciiTrace(MockInterface $journal, string $niveau, string $message, array $contexte): void
{
    $journal->shouldHaveReceived($niveau)
        ->once()
        ->withArgs(static fn (string $messageEcrit, array $contexteEcrit): bool => $messageEcrit === $message && $contexteEcrit === $contexte);

    expect(json_encode($contexte, JSON_THROW_ON_ERROR))->not->toContain('@');
}

/**
 * Les adresses de tous les comptes, par identifiant, telles que la base les garde.
 *
 * @return array<int, string>
 */
function domainesEnAsciiAdresses(): array
{
    /** @var array<int, string> $adresses */
    $adresses = DB::table('users')->orderBy('id')->pluck('email', 'id')->all();

    return $adresses;
}

it('écrit en ASCII un domaine internationalisé, sans avis ni vérification ni liaison perdues, et le compte se connecte sous chaque forme', function (): void {
    Notification::fake();
    Carbon::setTestNow('2026-11-05 09:00:00');
    $compte = User::factory()->create(['email' => 'jean@bücher.example.org', 'password' => 'Un-mot-de-passe-solide-42!']);
    $compte->forceFill(['provider' => 'google', 'provider_id' => '4242', 'liaison_prouvee_le' => now()])->save();
    $accentue = User::factory()->create(['email' => 'hélène@bücher.example.org']);
    $avant = DB::table('users')->where('id', $compte->id)->first();
    $journalDActivite = DB::table('activity_log')->count();
    Carbon::setTestNow('2026-11-05 10:00:00');
    $journal = domainesEnAsciiJournal();

    domainesEnAsciiMigrer();

    $apres = DB::table('users')->where('id', $compte->id)->first();
    assert($avant !== null && $apres !== null);

    expect($apres->email)->toBe('jean@xn--bcher-kva.example.org')
        ->and($apres->email_verified_at)->toBe($avant->email_verified_at)
        ->and($apres->liaison_prouvee_le)->toBe($avant->liaison_prouvee_le)
        ->and($apres->provider)->toBe('google')
        ->and($apres->updated_at)->toBe($avant->updated_at)
        ->and($accentue->refresh()->email)->toBe('hélène@xn--bcher-kva.example.org')
        ->and(DB::table('activity_log')->count())->toBe($journalDActivite)
        ->and(User::query()->count())->toBe(2);

    Notification::assertNothingSent();
    domainesEnAsciiTrace($journal, 'info', 'Adresses de compte écrites en ASCII (#2168)', ['comptes' => [$compte->id, $accentue->id]]);

    foreach (['jean@xn--bcher-kva.example.org', 'jean@bücher.example.org'] as $forme) {
        post(route('login'), ['email' => $forme, 'password' => 'Un-mot-de-passe-solide-42!'])->assertRedirect(route('dashboard'));
        assertAuthenticatedAs($compte);
        auth()->guard('web')->logout();
    }

    // La forme Unicode occupait aux yeux de la collation l'adresse ASCII voisine d'un autre domaine.
    expect(DB::table('users')->where('email', 'jean@bucher.example.org')->exists())->toBeFalse();
});

it('laisse l’adresse dont la forme ASCII est portée par un autre compte, même en capitales, et liste les deux par identifiant et par état', function (): void {
    $porteur = User::factory()->create(['email' => 'NOE@XN--BCHER-KVA.EXAMPLE.ORG']);
    $laisse = User::factory()->enAttente()->create(['email' => 'noe@bücher.example.org']);
    $journal = domainesEnAsciiJournal();
    $adresses = domainesEnAsciiAdresses();

    domainesEnAsciiMigrer();

    expect(domainesEnAsciiAdresses())->toBe($adresses);
    domainesEnAsciiTrace($journal, 'warning', 'Adresse de compte laissée en Unicode : sa forme ASCII est celle d’un autre compte (#2168)', [
        'compte' => $laisse->id,
        'etat' => 'en attente',
        'comptes_de_la_forme' => [['compte' => $porteur->id, 'etat' => 'ouvert']],
    ]);
    $journal->shouldNotHaveReceived('info');
});

/*
 * La collation, fondée sur UCA 4.0.0, ne connaît pas la casse glagolitique :
 * elle distingue « ⰰ » de « Ⰰ », et l'index unique admet les deux comptes,
 * que la conversion écrit l'un et l'autre « xn--tej ». Un plan calculé
 * d'avance convertirait le premier et tomberait sur l'index au second : la
 * migration laisse les deux, et les liste ensemble. La casse de la partie
 * locale, que PHP distingue et la collation non, ne les sépare pas.
 */
it('laisse deux adresses en Unicode qui ont la même forme ASCII, et les liste ensemble', function (string $premiere, string $seconde): void {
    $premier = User::factory()->create(['email' => $premiere]);
    $second = User::factory()->create(['email' => $seconde]);
    $tiers = User::factory()->create(['email' => 'tiers@bücher.example.org']);
    $journal = domainesEnAsciiJournal();

    domainesEnAsciiMigrer();

    expect($premier->refresh()->email)->toBe($premiere)
        ->and($second->refresh()->email)->toBe($seconde)
        ->and($tiers->refresh()->email)->toBe('tiers@xn--bcher-kva.example.org')
        ->and(static fn (): int => DB::table('users')->whereIn('id', [$premier->id, $second->id])->update(['email' => 'jean@xn--tej.example.org']))
        ->toThrow(UniqueConstraintViolationException::class);

    domainesEnAsciiTrace($journal, 'warning', 'Adresses de compte laissées en Unicode : elles ont la même forme ASCII (#2168)', [
        'comptes' => [['compte' => $premier->id, 'etat' => 'ouvert'], ['compte' => $second->id, 'etat' => 'ouvert']],
    ]);
    domainesEnAsciiTrace($journal, 'info', 'Adresses de compte écrites en ASCII (#2168)', ['comptes' => [$tiers->id]]);
})->with([
    'une minuscule et sa capitale glagolitiques' => ['jean@ⰰ.example.org', 'jean@Ⰰ.example.org'],
    'et une partie locale d’une autre casse' => ['Jean@ⰰ.example.org', 'jean@Ⰰ.example.org'],
]);

it('laisse tel quel un domaine que la forme canonique ne convertit pas, et le liste par identifiant', function (): void {
    $straße = User::factory()->create(['email' => 'sam@straße.example.org']);
    $pleineChasse = User::factory()->create(['email' => "jean@\u{FF45}xample.org"]);
    $commentaire = User::factory()->create(['email' => 'jean@bücher.example(sud).org']);
    $journal = domainesEnAsciiJournal();
    $adresses = domainesEnAsciiAdresses();

    domainesEnAsciiMigrer();

    expect(domainesEnAsciiAdresses())->toBe($adresses);
    domainesEnAsciiTrace($journal, 'warning', 'Adresses de compte laissées telles quelles : la forme canonique ne convertit pas leur domaine (#2168)', [
        'comptes' => [$straße->id, $pleineChasse->id, $commentaire->id],
    ]);
});

/*
 * `users.email` tient 255 caractères, et la forme ASCII d'un domaine
 * internationalisé est plus longue que sa forme Unicode : chaque label
 * converti s'allonge de « xn-- » et de son encodage. Une adresse d'au plus
 * 255 caractères, que l'inscription d'avant #2001 admettait (`email`,
 * `max:255`), peut donc avoir une forme qui ne tient pas dans la colonne.
 * L'écrire, pour mesurer le partage comme pour convertir, lève une erreur du
 * serveur qui n'est pas celle de l'index unique : la migration s'arrêtait
 * avant toute écriture, sans convertir aucun compte, et le démarrage avec
 * elle. Elle laisse cette adresse, la liste par identifiant, et convertit
 * les autres.
 */
it('laisse une adresse dont la forme ASCII ne tient pas dans la colonne, la liste par identifiant, et convertit les autres', function (string $adresse): void {
    $long = User::factory()->create(['email' => $adresse]);
    $tiers = User::factory()->create(['email' => 'tiers@bücher.example.org']);
    $journal = domainesEnAsciiJournal();

    expect(Validator::make(['email' => $adresse], ['email' => 'required|string|lowercase|email|max:255'])->passes())->toBeTrue()
        ->and(mb_strlen(AdresseCanonique::de($adresse)))->toBeGreaterThan(255);

    domainesEnAsciiMigrer();

    expect($long->refresh()->email)->toBe($adresse)
        ->and($tiers->refresh()->email)->toBe('tiers@xn--bcher-kva.example.org');
    domainesEnAsciiTrace($journal, 'warning', 'Adresses de compte laissées en Unicode : leur forme ASCII ne tient pas dans la colonne (#2168)', ['comptes' => [$long->id]]);
    domainesEnAsciiTrace($journal, 'info', 'Adresses de compte écrites en ASCII (#2168)', ['comptes' => [$tiers->id]]);
})->with([
    'une longue partie locale' => [str_repeat('a', 235).'@bücher.example.org'],
    'un long domaine' => [str_repeat('j', 47).'@'.implode('.', array_fill(0, 4, str_repeat('a', 49).'ü')).'.org'],
]);

it('ne touche ni une partie locale hors ASCII sous un domaine ASCII, ni une adresse déjà en ASCII, et ne trace rien', function (): void {
    User::factory()->create(['email' => 'jéan@example.org']);
    User::factory()->create(['email' => 'jean@xn--bcher-kva.example.org']);
    User::factory()->create(['email' => 'Camille.Martin@Example.org']);
    $journal = domainesEnAsciiJournal();
    $adresses = domainesEnAsciiAdresses();

    domainesEnAsciiMigrer();

    expect(domainesEnAsciiAdresses())->toBe($adresses);
    $journal->shouldNotHaveReceived('info');
    $journal->shouldNotHaveReceived('warning');
});

/*
 * Une seconde passe ne trouve plus que les adresses laissées, et n'écrit
 * rien : seul le partage d'une forme se mesure encore par l'index, dans la
 * transaction que la migration annule. Chaque écriture se compte avec la
 * profondeur de transaction où elle part ; celle du test est la première.
 */
it('ne change rien à la seconde passe', function (): void {
    User::factory()->create(['email' => 'jean@bücher.example.org']);
    User::factory()->create(['email' => 'NOE@XN--BCHER-KVA.EXAMPLE.ORG']);
    User::factory()->create(['email' => 'noe@bücher.example.org']);
    User::factory()->create(['email' => 'jean@ⰰ.example.org']);
    User::factory()->create(['email' => 'jean@Ⰰ.example.org']);
    User::factory()->create(['email' => 'sam@straße.example.org']);
    User::factory()->create(['email' => 'jéan@example.org']);

    domainesEnAsciiMigrer();
    $apresLaPremiere = DB::table('users')->orderBy('id')->get()->all();
    $profondeurDuTest = DB::transactionLevel();
    $ecrituresGardees = 0;
    DB::listen(static function (QueryExecuted $requete) use (&$ecrituresGardees, $profondeurDuTest): void {
        if (preg_match('/^\s*(update|insert|delete)\b/i', $requete->sql) === 1 && DB::transactionLevel() <= $profondeurDuTest) {
            $ecrituresGardees++;
        }
    });

    domainesEnAsciiMigrer();

    expect(DB::table('users')->orderBy('id')->get()->all())->toEqual($apresLaPremiere)
        ->and(domainesEnAsciiAdresses())->toContain('jean@xn--bcher-kva.example.org')
        ->and(domainesEnAsciiAdresses())->toContain('noe@bücher.example.org')
        ->and(domainesEnAsciiAdresses())->toContain('jean@ⰰ.example.org')
        ->and($ecrituresGardees)->toBe(0)
        ->and(DB::transactionLevel())->toBe($profondeurDuTest)
        ->and(User::query()->count())->toBe(7);
});
