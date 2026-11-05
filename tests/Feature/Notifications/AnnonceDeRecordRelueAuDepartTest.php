<?php

declare(strict_types=1);

use App\Jobs\SyncPersonalRecord;
use App\Jobs\SyncUserAchievements;
use App\Jobs\SyncUserGoals;
use App\Models\Exercise;
use App\Models\NotificationPreference;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Notifications\PersonalRecordAchieved;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use NotificationChannels\WebPush\WebPushChannel;

use function Pest\Laravel\actingAs;

/*
 * Une annonce de record relit le record au moment où elle part (#2155).
 *
 * L'annonce d'un record attend en file derrière la synchronisation qui l'a
 * établi. Elle ne portait que le record, que Laravel relit à son départ : si la
 * série avait été supprimée ou corrigée entre-temps, et qu'une autre série tenait
 * encore le record, la reconstruction réécrivait la même ligne à la valeur
 * d'avant, et l'annonce partait avec elle. La personne recevait de nouveau
 * « … avec 90 kg » pour un record de 90 kg déjà annoncé, au centre de
 * notifications et en push. Deux séries validées avant le passage du worker
 * donnaient de même six annonces de la seconde, et aucune de la première.
 *
 * L'annonce retient désormais la série qui a établi le record, la valeur qu'elle
 * y a écrite et celle que le record de ce type portait avant elle. Relu à son
 * départ, le record ne s'annonce pas quand une autre série le tient plus haut
 * que cette valeur écrite (elle l'a battue, et s'annonce elle-même), ni quand il
 * ne dépasse plus la valeur d'avant. Sinon le message dit la valeur qu'il porte
 * alors, y compris quand une série validée après, sans la battre, l'a repris.
 *
 * Une reconstruction pouvait aussi donner le record à une série validée dont la
 * synchronisation attendait encore en file (#2185) : la série qui le tenait,
 * corrigée, décochée ou supprimée, ou son exercice retiré de la séance, avant
 * le passage du worker. Cette synchronisation ne trouvait plus rien à battre, et
 * personne n'annonçait le record. La reconstruction l'annonce désormais pour
 * cette série, avec la valeur que le record portait avant elle, et l'annonce se
 * juge à son départ comme les autres ; elle ne dit rien quand le record ne
 * dépasse pas celui que la personne tenait, ni quand la série le tenait déjà.
 *
 * Les cas jouent le chemin de production : file `database`, synchronisation des
 * records mise en file par `Set::saved` (`annonceRelueCheminDeProduction()`), et
 * vrais workers (`queue:work --once`), un travail à la fois
 * (`.ai/rules/tests.md`). Chaque retouche passe par la route que la page de
 * séance appelle, après le passage de la synchronisation et avant celui des
 * annonces. Le compte a l'annonce des records activée en base et en push : le
 * canal push est atteint (`NotificationSent`), même si rien n'y part faute
 * d'abonnement.
 */

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Le chemin des records en production : file `database`, et
 * `SyncPersonalRecord` mis en file par `Set::saved` au lieu de tourner dans la
 * requête (`AppServiceProvider::registerSetEvents()`). Seule la configuration
 * que lit cette condition change : la connexion, déjà ouverte, reste celle du
 * test.
 */
function annonceRelueCheminDeProduction(): void
{
    config([
        'queue.default' => 'database',
        'app.env' => 'production',
        'database.connections.mysql.database' => 'gym_tracker',
    ]);
}

/**
 * Un pratiquant qui a activé l'annonce de ses records, en base et en push, et
 * une ligne de squat dans une séance, posés pendant que la file est encore
 * synchrone.
 *
 * @return array{0: User, 1: WorkoutLine}
 */
function annonceRelueCompte(): array
{
    $user = User::factory()->create();
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => 'personal_record',
        'is_enabled' => true,
        'is_push_enabled' => true,
    ]);
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Squat', 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $user->id]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

    return [$user, $ligne];
}

/**
 * Valide une série d'une répétition par l'API, comme la page de séance, et la
 * rend.
 */
function annonceRelueValider(User $user, WorkoutLine $ligne, float $poids): Set
{
    $id = actingAs($user, 'sanctum')
        ->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $ligne->id,
            'weight' => $poids,
            'reps' => 1,
            'is_warmup' => false,
            'is_completed' => true,
        ])
        ->assertCreated()
        ->json('data.id');
    assert(is_int($id));

    return Set::query()->findOrFail($id);
}

/**
 * Corrige le poids d'une série par l'API, comme la page de séance.
 */
function annonceRelueCorriger(User $user, Set $serie, float $poids): void
{
    actingAs($user, 'sanctum')
        ->patchJson(route('api.v1.sets.update', $serie), ['weight' => $poids])
        ->assertOk();
}

/**
 * Supprime une série par l'API, comme la page de séance.
 */
function annonceRelueSupprimer(User $user, Set $serie): void
{
    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.sets.destroy', $serie))
        ->assertNoContent();
}

/**
 * Décoche une série par l'API, comme la page de séance.
 */
function annonceRelueDecocher(User $user, Set $serie): void
{
    actingAs($user, 'sanctum')
        ->patchJson(route('api.v1.sets.update', $serie), ['is_completed' => false])
        ->assertOk();
}

/**
 * Retire un exercice de la séance par l'API, comme la page de séance : ses
 * séries partent avec lui, par la base.
 */
function annonceRelueRetirerLaLigne(User $user, WorkoutLine $ligne): void
{
    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.workout-lines.destroy', $ligne))
        ->assertNoContent();
}

/**
 * Une seconde ligne du même exercice dans la même séance, posée pendant que la
 * file est encore synchrone : retirer l'autre ligne ne l'emporte pas.
 */
function annonceRelueAutreLigne(WorkoutLine $ligne): WorkoutLine
{
    return WorkoutLine::factory()->create(['workout_id' => $ligne->workout_id, 'exercise_id' => $ligne->exercise_id]);
}

/**
 * Le nombre de records de l'exercice que la série tient, à cette valeur.
 */
function annonceRelueRecordsTenusPar(User $user, Set $serie, float $valeur): int
{
    return PersonalRecord::query()->where('user_id', $user->id)->where('set_id', $serie->id)->where('value', $valeur)->count();
}

/**
 * Traite le prochain travail disponible par un vrai worker.
 */
function annonceRelueTraiterLeSuivant(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
}

/**
 * Vide la file par de vrais workers, un travail à la fois. Un travail remis
 * en file à plus tard se reprend à son heure : l'horloge avance jusqu'à elle
 * (`available_at`, une seconde pleine). Une file qui garde des travaux après
 * quarante tours lève, au lieu de laisser le cas conclure sur une file à moitié
 * vidée.
 */
function annonceRelueViderLaFile(): void
{
    foreach (range(1, 40) as $_tour) {
        $disponibleA = DB::table('jobs')->min('available_at');

        if (! is_numeric($disponibleA)) {
            return;
        }

        if ((int) $disponibleA > now()->getTimestamp()) {
            Carbon::setTestNow(Carbon::createFromTimestamp((int) $disponibleA, date_default_timezone_get()));
        }

        annonceRelueTraiterLeSuivant();
    }

    throw new LogicException('La file garde des travaux après quarante tours.');
}

/**
 * Les travaux de la file, dans l'ordre où ils y sont entrés, par le nom que
 * Horizon affiche (la classe de l'avis pour une notification).
 *
 * @return list<string>
 */
function annonceRelueContenuDeLaFile(): array
{
    return array_values(DB::table('jobs')->orderBy('id')->pluck('payload')
        ->map(static function (mixed $charge): string {
            assert(is_string($charge));
            $decodee = json_decode($charge, true, flags: JSON_THROW_ON_ERROR);
            assert(is_array($decodee) && is_string($decodee['displayName'] ?? null));

            return $decodee['displayName'];
        })
        ->all());
}

/**
 * Les travaux que les workers ont rangés dans `failed_jobs`.
 *
 * @return list<string>
 */
function annonceRelueTravauxEnEchec(): array
{
    return array_values(DB::table('failed_jobs')->orderBy('id')->pluck('exception')
        ->map(static fn (mixed $exception): string => is_string($exception) ? (string) strtok($exception, "\n") : '')
        ->all());
}

/**
 * Note chaque annonce de record qui part, par canal, avec le texte que ce canal
 * reçoit : le message du centre de notifications, le corps du push.
 *
 * @return Closure(): list<string> Les envois notés, triés.
 */
function annonceRelueSuivreLesEnvois(): Closure
{
    /** @var list<string> $envois */
    $envois = [];

    Event::listen(NotificationSent::class, static function (NotificationSent $evenement) use (&$envois): void {
        if (! $evenement->notification instanceof PersonalRecordAchieved || ! $evenement->notifiable instanceof User) {
            return;
        }

        $texte = $evenement->channel === WebPushChannel::class
            ? $evenement->notification->toWebPush($evenement->notifiable, null)->toArray()['body'] ?? null
            : $evenement->notification->toArray($evenement->notifiable)['message'] ?? null;
        assert(is_string($texte));

        $envois[] = ($evenement->channel === WebPushChannel::class ? 'push' : $evenement->channel).' : '.$texte;
    });

    // Par référence : une fonction fléchée copierait la liste, vide, à sa création.
    return static function () use (&$envois): array {
        return annonceRelueTrier($envois);
    };
}

/**
 * Des textes triés : les envois et les notifications se comparent sans
 * dépendre de l'ordre dans lequel les workers les ont traités.
 *
 * @param  list<string>  $textes
 * @return list<string>
 */
function annonceRelueTrier(array $textes): array
{
    sort($textes);

    return $textes;
}

/**
 * Les trois messages d'une série qui bat les trois records du squat d'une
 * seule répétition (poids maximum, 1RM estimé, volume par série), triés.
 *
 * @return list<string>
 */
function annonceRelueMessages(string $valeur): array
{
    return annonceRelueTrier(array_map(
        static fn (string $libelle): string => "Félicitations ! Tu as battu ton record de {$libelle} sur l'exercice Squat avec {$valeur}\u{00A0}kg.",
        ['poids maximum', '1RM estimé', 'volume par série'],
    ));
}

/**
 * Les envois de ces trois annonces, chacune au centre de notifications et en
 * push, triés comme `annonceRelueSuivreLesEnvois()` les rend.
 *
 * @return list<string>
 */
function annonceRelueEnvoisDe(string $valeur): array
{
    return annonceRelueTrier([
        ...array_map(static fn (string $message): string => 'database : '.$message, annonceRelueMessages($valeur)),
        ...array_map(static fn (string $message): string => 'push : '.$message, annonceRelueMessages($valeur)),
    ]);
}

/**
 * Les messages des notifications que le compte a en base, triés.
 *
 * @return list<string>
 */
function annonceRelueMessagesEnBase(User $user): array
{
    return annonceRelueTrier(array_values(DB::table('notifications')
        ->where('notifiable_type', $user->getMorphClass())
        ->where('notifiable_id', $user->id)
        ->pluck('data')
        ->map(static function (mixed $donnees): string {
            assert(is_string($donnees));
            $decodees = json_decode($donnees, true, flags: JSON_THROW_ON_ERROR);
            assert(is_array($decodees) && is_string($decodees['message'] ?? null));

            return $decodees['message'];
        })
        ->all()));
}

/**
 * La charge d'une annonce telle que l'écrivait la version d'avant #2155 : la
 * même, sans la série, la valeur écrite ni la valeur d'avant que l'avis
 * retient désormais. Les trois propriétés, privées (leur nom sérialisé est
 * précédé de celui de la classe, entre deux octets nuls), sortent de l'avis
 * sérialisé, dont le compte de propriétés baisse d'autant.
 */
function annonceRelueChargeDAvant(string $charge): string
{
    $decodee = json_decode($charge, true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($decodee) && is_array($decodee['data'] ?? null) && is_string($decodee['data']['command'] ?? null));

    $retirees = 0;
    $commande = preg_replace(
        '/s:\d+:"\x00[^\x00"]+\x00serie";(?:i:\d+|N);|s:\d+:"\x00[^\x00"]+\x00(?:valeurEcrite|valeurDAvant)";(?:d:[^;]+|N);/',
        '',
        $decodee['data']['command'],
        -1,
        $retirees,
    );
    assert(is_string($commande) && $retirees === 3);

    $entete = 'O:'.strlen(PersonalRecordAchieved::class).':"'.PersonalRecordAchieved::class.'":';
    $commande = preg_replace_callback(
        '/'.preg_quote($entete, '/').'(\d+):/',
        static fn (array $prises): string => $entete.((int) $prises[1] - $retirees).':',
        $commande,
        1,
        $avis,
    );
    assert(is_string($commande) && $avis === 1);

    $decodee['data']['command'] = $commande;

    return json_encode($decodee, JSON_THROW_ON_ERROR);
}

/**
 * Les annonces qu'un worker vient de mettre en file derrière la
 * synchronisation d'une série qui bat les trois records : chacune sur deux
 * canaux.
 *
 * @return list<string>
 */
function annonceRelueFileApresLaSynchronisation(): array
{
    return [
        SyncUserAchievements::class, SyncUserGoals::class,
        ...array_fill(0, 6, PersonalRecordAchieved::class),
    ];
}

it('n’annonce une série retouchée avant le départ de ses annonces que si elle tient encore le record, à la valeur qu’il porte alors, sur le chemin de production', function (string $retouche, ?string $valeurAnnoncee): void {
    [$user, $ligne] = annonceRelueCompte();

    // Le record de 90 kg, établi et annoncé dans la requête (file synchrone).
    annonceRelueValider($user, $ligne, 90);

    expect(annonceRelueMessagesEnBase($user))->toBe(annonceRelueMessages('90'));

    annonceRelueCheminDeProduction();
    $envois = annonceRelueSuivreLesEnvois();

    // La série de 110 kg bat les trois records ; ses annonces attendent.
    $serie = annonceRelueValider($user, $ligne, 110);
    annonceRelueTraiterLeSuivant();

    expect(annonceRelueContenuDeLaFile())->toBe(annonceRelueFileApresLaSynchronisation())
        ->and(PersonalRecord::query()->where('set_id', $serie->id)->count())->toBe(3);

    match ($retouche) {
        'aucune' => null,
        'supprimée' => annonceRelueSupprimer($user, $serie),
        'corrigée à 10 kg' => annonceRelueCorriger($user, $serie, 10),
        'corrigée à 100 kg' => annonceRelueCorriger($user, $serie, 100),
        'corrigée à 120 kg' => annonceRelueCorriger($user, $serie, 120),
        default => throw new LogicException('Retouche inconnue : '.$retouche),
    };

    annonceRelueViderLaFile();

    expect(annonceRelueTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($envois())->toBe($valeurAnnoncee === null ? [] : annonceRelueEnvoisDe($valeurAnnoncee))
        ->and(annonceRelueMessagesEnBase($user))->toBe($valeurAnnoncee === null
            ? annonceRelueMessages('90')
            : annonceRelueTrier([...annonceRelueMessages('90'), ...annonceRelueMessages($valeurAnnoncee)]));
})->with([
    // Contre-épreuve : sans retouche, les trois annonces partent comme avant.
    'sans retouche' => ['aucune', '110'],
    'série supprimée' => ['supprimée', null],
    'série corrigée sous l’ancien record' => ['corrigée à 10 kg', null],
    // Elle garde le record : ses annonces partent, avec la valeur corrigée.
    'série corrigée au-dessus de l’ancien record' => ['corrigée à 100 kg', '100'],
    // Elle le garde aussi, au-dessus de ce qu'elle avait écrit : aucune autre
    // série ne l'a battue.
    'série corrigée au-dessus de sa propre valeur' => ['corrigée à 120 kg', '120'],
]);

it('annonce le record qu’une série validée après elle reprend sans l’avoir battue, quand elle est supprimée ou corrigée avant le départ de ses annonces, sur le chemin de production', function (float $poidsDeLaSerie, float $poidsDeLaSuivante, string $retouche): void {
    [$user, $ligne] = annonceRelueCompte();

    // Le record de 90 kg, établi et annoncé dans la requête (file synchrone).
    annonceRelueValider($user, $ligne, 90);
    annonceRelueCheminDeProduction();
    $envois = annonceRelueSuivreLesEnvois();

    // La série bat les trois records ; ses annonces attendent. La suivante, à
    // 100 kg, ne la bat pas : sa synchronisation attend derrière ces annonces,
    // et n'aura rien à annoncer.
    $serie = annonceRelueValider($user, $ligne, $poidsDeLaSerie);
    annonceRelueTraiterLeSuivant();
    $suivante = annonceRelueValider($user, $ligne, $poidsDeLaSuivante);

    expect(annonceRelueContenuDeLaFile())->toBe([...annonceRelueFileApresLaSynchronisation(), SyncPersonalRecord::class]);

    match ($retouche) {
        'supprimée' => annonceRelueSupprimer($user, $serie),
        'corrigée à 95 kg' => annonceRelueCorriger($user, $serie, 95),
        default => throw new LogicException('Retouche inconnue : '.$retouche),
    };

    // La suivante tient désormais les trois records, à 100 kg, au-dessus des
    // 90 kg annoncés : un vrai record, que seules les annonces de la série
    // peuvent encore dire.
    expect(PersonalRecord::query()->where('user_id', $user->id)->where('set_id', $suivante->id)->where('value', 100)->count())->toBe(3);

    annonceRelueViderLaFile();

    expect(annonceRelueTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($envois())->toBe(annonceRelueEnvoisDe('100'))
        ->and(annonceRelueMessagesEnBase($user))->toBe(annonceRelueTrier([...annonceRelueMessages('90'), ...annonceRelueMessages('100')]));
})->with([
    'à égalité, la série supprimée' => [100.0, 100.0, 'supprimée'],
    'à égalité, la série corrigée sous elle' => [100.0, 100.0, 'corrigée à 95 kg'],
    'entre l’ancien record et la série, la série supprimée' => [110.0, 100.0, 'supprimée'],
]);

it('n’annonce que la dernière de deux séries validées avant le passage du worker, sur le chemin de production', function (): void {
    [$user, $ligne] = annonceRelueCompte();
    annonceRelueCheminDeProduction();
    $envois = annonceRelueSuivreLesEnvois();

    // Les deux premières séries de l'exercice : la seconde se synchronise
    // avant que les annonces de la première ne partent.
    annonceRelueValider($user, $ligne, 100);
    annonceRelueValider($user, $ligne, 110);

    expect(annonceRelueContenuDeLaFile())
        ->toBe([SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class, SyncPersonalRecord::class]);

    annonceRelueViderLaFile();

    expect(annonceRelueTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($envois())->toBe(annonceRelueEnvoisDe('110'))
        ->and(annonceRelueMessagesEnBase($user))->toBe(annonceRelueMessages('110'));
});

it('n’annonce jamais un record que la personne tenait déjà, même quand la série qui l’a battu le tient encore, sur le chemin de production', function (float $poidsCorrige): void {
    [$user, $ligne] = annonceRelueCompte();
    $premiere = annonceRelueValider($user, $ligne, 90);
    annonceRelueCheminDeProduction();
    $envois = annonceRelueSuivreLesEnvois();

    $serie = annonceRelueValider($user, $ligne, 110);
    annonceRelueTraiterLeSuivant();

    expect(annonceRelueContenuDeLaFile())->toBe(annonceRelueFileApresLaSynchronisation());

    // La série de 90 kg disparaît, puis celle de 110 kg est corrigée : seule
    // série de l'exercice, elle tient les records, à 90 kg ou en dessous.
    annonceRelueSupprimer($user, $premiere);
    annonceRelueCorriger($user, $serie, $poidsCorrige);

    expect(PersonalRecord::query()->where('user_id', $user->id)->where('set_id', $serie->id)->count())->toBe(3);

    annonceRelueViderLaFile();

    expect(annonceRelueTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($envois())->toBe([])
        ->and(annonceRelueMessagesEnBase($user))->toBe(annonceRelueMessages('90'));
})->with([
    'à la valeur du record d’avant' => [90.0],
    'sous la valeur du record d’avant' => [80.0],
]);

it('n’annonce qu’une fois le record d’une série corrigée sous l’ancien record puis rétablie avant le départ de ses annonces, sur le chemin de production', function (): void {
    [$user, $ligne] = annonceRelueCompte();
    annonceRelueValider($user, $ligne, 90);
    annonceRelueCheminDeProduction();
    $envois = annonceRelueSuivreLesEnvois();

    $serie = annonceRelueValider($user, $ligne, 110);
    annonceRelueTraiterLeSuivant();

    // Une faute de frappe, rattrapée aussitôt : la série rend les records à
    // celle de 90 kg, puis se synchronise de nouveau et les reprend, après le
    // départ de ses premières annonces.
    annonceRelueCorriger($user, $serie, 80);
    annonceRelueCorriger($user, $serie, 110);

    expect(annonceRelueContenuDeLaFile())->toBe([...annonceRelueFileApresLaSynchronisation(), SyncPersonalRecord::class]);

    annonceRelueViderLaFile();

    expect(annonceRelueTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($envois())->toBe(annonceRelueEnvoisDe('110'))
        ->and(annonceRelueMessagesEnBase($user))->toBe(annonceRelueTrier([...annonceRelueMessages('90'), ...annonceRelueMessages('110')]));
});

it('envoie sans échec une annonce mise en file avant le correctif, qui ne porte ni sa série, ni la valeur écrite, ni la valeur d’avant', function (): void {
    [$user, $ligne] = annonceRelueCompte();
    $serie = annonceRelueValider($user, $ligne, 110);
    $record = PersonalRecord::query()->where('set_id', $serie->id)->where('type', 'max_weight')->sole();

    config(['queue.default' => 'database']);
    $envois = annonceRelueSuivreLesEnvois();

    $user->notify(new PersonalRecordAchieved($record, 90.0));

    // Chaque charge redevient celle qu'écrivait la version d'avant : l'avis
    // ne porte que le record. La file le restaure sans passer par son
    // constructeur.
    foreach (DB::table('jobs')->pluck('payload', 'id') as $id => $charge) {
        assert(is_string($charge));
        DB::table('jobs')->where('id', $id)->update(['payload' => annonceRelueChargeDAvant($charge)]);
    }

    $charges = DB::table('jobs')->pluck('payload')->implode("\n");

    expect(annonceRelueContenuDeLaFile())->toBe([PersonalRecordAchieved::class, PersonalRecordAchieved::class])
        ->and($charges)->not->toContain('serie')
        ->and($charges)->not->toContain('valeurEcrite')
        ->and($charges)->not->toContain('valeurDAvant');

    annonceRelueViderLaFile();

    $message = "Félicitations ! Tu as battu ton record de poids maximum sur l'exercice Squat avec 110\u{00A0}kg.";

    expect(annonceRelueTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($envois())->toBe(['database : '.$message, 'push : '.$message]);
});

it('annonce le record qu’une reconstruction donne à une série dont la synchronisation attend encore, une fois par record, sur le chemin de production', function (string $retouche): void {
    [$user, $ligne] = annonceRelueCompte();
    $autreLigne = annonceRelueAutreLigne($ligne);

    // Le record de 90 kg, établi et annoncé dans la requête (file synchrone).
    $tenante = annonceRelueValider($user, $ligne, 90);
    annonceRelueCheminDeProduction();
    $envois = annonceRelueSuivreLesEnvois();

    // La série de 110 kg, validée : sa synchronisation attend son worker.
    $lourde = annonceRelueValider($user, $autreLigne, 110);

    expect(annonceRelueContenuDeLaFile())->toBe([SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class]);

    match ($retouche) {
        'corrigée à 85 kg' => annonceRelueCorriger($user, $tenante, 85),
        'supprimée' => annonceRelueSupprimer($user, $tenante),
        'décochée' => annonceRelueDecocher($user, $tenante),
        'son exercice retiré' => annonceRelueRetirerLaLigne($user, $ligne),
        default => throw new LogicException('Retouche inconnue : '.$retouche),
    };

    // La reconstruction a donné les trois records à la série de 110 kg avant sa
    // synchronisation, qui n'aura plus rien à battre.
    expect(annonceRelueRecordsTenusPar($user, $lourde, 110))->toBe(3);

    annonceRelueViderLaFile();

    expect(annonceRelueTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($envois())->toBe(annonceRelueEnvoisDe('110'))
        ->and(annonceRelueMessagesEnBase($user))->toBe(annonceRelueTrier([...annonceRelueMessages('90'), ...annonceRelueMessages('110')]));
})->with([
    // La sonde de l'issue : la série de 90 kg corrigée à 85.
    'la série qui le tenait corrigée sous lui' => ['corrigée à 85 kg'],
    'la série qui le tenait supprimée' => ['supprimée'],
    'la série qui le tenait décochée' => ['décochée'],
    'l’exercice de la série qui le tenait retiré de la séance' => ['son exercice retiré'],
]);

it('annonce le record qu’une reconstruction donne à une série plus lourde encore en attente, quand la série dont les annonces attendent est supprimée ou corrigée, sur le chemin de production', function (string $retouche): void {
    [$user, $ligne] = annonceRelueCompte();

    // Le record de 90 kg, établi et annoncé dans la requête (file synchrone).
    annonceRelueValider($user, $ligne, 90);
    annonceRelueCheminDeProduction();
    $envois = annonceRelueSuivreLesEnvois();

    // La série de 110 kg bat les trois records ; ses annonces attendent. La
    // série de 120 kg, validée ensuite, attend sa synchronisation derrière elles.
    $serie = annonceRelueValider($user, $ligne, 110);
    annonceRelueTraiterLeSuivant();
    $plusLourde = annonceRelueValider($user, $ligne, 120);

    expect(annonceRelueContenuDeLaFile())->toBe([...annonceRelueFileApresLaSynchronisation(), SyncPersonalRecord::class]);

    match ($retouche) {
        'supprimée' => annonceRelueSupprimer($user, $serie),
        'corrigée à 100 kg' => annonceRelueCorriger($user, $serie, 100),
        default => throw new LogicException('Retouche inconnue : '.$retouche),
    };

    // La reconstruction a donné les trois records à la série de 120 kg : les
    // annonces de la série de 110 kg se taisent devant elle (#2155), et sa
    // synchronisation n'aura plus rien à battre.
    expect(annonceRelueRecordsTenusPar($user, $plusLourde, 120))->toBe(3);

    annonceRelueViderLaFile();

    expect(annonceRelueTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($envois())->toBe(annonceRelueEnvoisDe('120'))
        ->and(annonceRelueMessagesEnBase($user))->toBe(annonceRelueTrier([...annonceRelueMessages('90'), ...annonceRelueMessages('120')]));
})->with([
    'la série supprimée' => ['supprimée'],
    'la série corrigée sous elle' => ['corrigée à 100 kg'],
]);

it('annonce le premier record d’un exercice qu’une reconstruction donne à une série dont la synchronisation attend encore, sur le chemin de production', function (): void {
    [$user, $ligne] = annonceRelueCompte();
    $autreLigne = annonceRelueAutreLigne($ligne);
    annonceRelueCheminDeProduction();
    $envois = annonceRelueSuivreLesEnvois();

    // Les deux premières séries de l'exercice, sur deux lignes : aucune
    // synchronisation n'est encore passée, l'exercice n'a aucun record.
    annonceRelueValider($user, $ligne, 90);
    $lourde = annonceRelueValider($user, $autreLigne, 110);

    expect(annonceRelueContenuDeLaFile())->toBe([SyncPersonalRecord::class, SyncUserAchievements::class, SyncUserGoals::class, SyncPersonalRecord::class])
        ->and(PersonalRecord::query()->where('user_id', $user->id)->count())->toBe(0);

    // L'exercice de la première retiré de la séance : la reconstruction crée
    // les trois records, au nom de la série de 110 kg.
    annonceRelueRetirerLaLigne($user, $ligne);

    expect(annonceRelueRecordsTenusPar($user, $lourde, 110))->toBe(3);

    annonceRelueViderLaFile();

    expect(annonceRelueTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($envois())->toBe(annonceRelueEnvoisDe('110'))
        ->and(annonceRelueMessagesEnBase($user))->toBe(annonceRelueMessages('110'));
});

it('n’annonce pas le record qu’une reconstruction donne à une série en attente sans dépasser celui que la personne tenait, sur le chemin de production', function (float $poidsDeLaTenante, float $poidsEnAttente, string $valeurTenue): void {
    [$user, $ligne] = annonceRelueCompte();
    $tenante = annonceRelueValider($user, $ligne, $poidsDeLaTenante);
    annonceRelueCheminDeProduction();
    $envois = annonceRelueSuivreLesEnvois();

    $enAttente = annonceRelueValider($user, $ligne, $poidsEnAttente);
    annonceRelueSupprimer($user, $tenante);

    // La série en attente tient les trois records sans dépasser ceux que la
    // personne tenait : aucune annonce n'entre en file.
    expect(annonceRelueRecordsTenusPar($user, $enAttente, $poidsEnAttente))->toBe(3)
        ->and(annonceRelueContenuDeLaFile())->not->toContain(PersonalRecordAchieved::class);

    annonceRelueViderLaFile();

    expect(annonceRelueTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($envois())->toBe([])
        ->and(annonceRelueMessagesEnBase($user))->toBe(annonceRelueMessages($valeurTenue));
})->with([
    'à égalité' => [100.0, 100.0, '100'],
    'plus légère' => [110.0, 100.0, '110'],
]);

it('n’annonce pas le record qu’une reconstruction a donné à une série en attente, quand celle-ci est supprimée avant le départ de l’annonce, sur le chemin de production', function (): void {
    [$user, $ligne] = annonceRelueCompte();
    $tenante = annonceRelueValider($user, $ligne, 90);
    annonceRelueCheminDeProduction();
    $envois = annonceRelueSuivreLesEnvois();

    $lourde = annonceRelueValider($user, $ligne, 110);
    annonceRelueCorriger($user, $tenante, 85);

    expect(annonceRelueRecordsTenusPar($user, $lourde, 110))->toBe(3);

    // La série de 110 kg disparaît avant le passage du worker : les records
    // reviennent à 85 kg, sous les 90 que la personne tenait.
    annonceRelueSupprimer($user, $lourde);
    annonceRelueViderLaFile();

    expect(annonceRelueTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(annonceRelueRecordsTenusPar($user, $tenante, 85))->toBe(3)
        ->and($envois())->toBe([])
        ->and(annonceRelueMessagesEnBase($user))->toBe(annonceRelueMessages('90'));
});

it('n’annonce pas de nouveau le record d’une série corrigée au-dessus de sa valeur après le départ de ses annonces, sur le chemin de production', function (): void {
    [$user, $ligne] = annonceRelueCompte();
    annonceRelueValider($user, $ligne, 90);
    annonceRelueCheminDeProduction();
    $envois = annonceRelueSuivreLesEnvois();

    // La série de 110 kg bat les trois records, et ses annonces partent.
    $serie = annonceRelueValider($user, $ligne, 110);
    annonceRelueViderLaFile();

    expect($envois())->toBe(annonceRelueEnvoisDe('110'));

    // Une faute de frappe rattrapée : la série garde les records, plus haut.
    // Une correction, pas un exploit : rien n'entre en file pour l'annoncer.
    annonceRelueCorriger($user, $serie, 120);

    expect(annonceRelueRecordsTenusPar($user, $serie, 120))->toBe(3)
        ->and(annonceRelueContenuDeLaFile())->not->toContain(PersonalRecordAchieved::class);

    annonceRelueViderLaFile();

    expect(annonceRelueTravauxEnEchec())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($envois())->toBe(annonceRelueEnvoisDe('110'))
        ->and(annonceRelueMessagesEnBase($user))->toBe(annonceRelueTrier([...annonceRelueMessages('90'), ...annonceRelueMessages('110')]));
});
