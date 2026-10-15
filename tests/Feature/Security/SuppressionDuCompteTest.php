<?php

declare(strict_types=1);

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\ErreurNavigateur;
use App\Models\ExceptionEnregistree;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\SessionGuard;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FilamentAdminPanel;

/*
 * Supprimer un compte efface aussi ce qui le désigne sans clé étrangère (#1935).
 *
 * Les tables liées au compte par `user_id` suivent par `ON DELETE CASCADE`.
 * Celles qui le désignent par une relation polymorphe — un couple
 * `*_type` / `*_id` — n'ont aucune clé étrangère : la base ne sait pas que la
 * ligne parle d'un compte, et rien ne la suivait. Les notifications, les
 * adresses et clés des appareils abonnés au push, les jetons d'API, les rôles
 * et permissions et l'historique d'activité restaient en base après une
 * suppression qui promettait de tout effacer.
 *
 * Chaque relation de `User::TRACES_POLYMORPHES` est semée ici, et le cas
 * exige qu'elle porte au moins une ligne avant la suppression : une relation
 * ajoutée à l'inventaire sans être semée fait tomber ce test au lieu de passer
 * à vide. `LesTablesPolymorphesSuiventLeCompteTest` exige de son côté que toute
 * colonne `*_type` de la base figure dans l'inventaire ou dans ses exceptions.
 *
 * Deux témoins ne doivent pas bouger : un compte voisin porteur des mêmes
 * lignes, et un administrateur qui porte le MÊME identifiant que le compte
 * supprimé. Les deux tables d'identité ont chacune leur séquence ; un filtre
 * sur le seul identifiant effacerait les rôles, les permissions et l'audit de
 * cet administrateur.
 */

/**
 * Une ligne dans chaque table polymorphe, au nom de ce propriétaire.
 */
function compteSupprimeSemerSesTraces(Model $proprietaire): void
{
    $type = $proprietaire->getMorphClass();
    $identifiant = $proprietaire->getKey();
    $maintenant = now();

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'temoin-de-suppression',
        'notifiable_type' => $type,
        'notifiable_id' => $identifiant,
        'data' => '{"message":"témoin"}',
        'created_at' => $maintenant,
        'updated_at' => $maintenant,
    ]);

    DB::table('push_subscriptions')->insert([
        'subscribable_type' => $type,
        'subscribable_id' => $identifiant,
        'endpoint' => 'https://push.example.org/'.Str::uuid(),
        'public_key' => 'cle-publique',
        'auth_token' => 'jeton-d-authentification',
        'created_at' => $maintenant,
        'updated_at' => $maintenant,
    ]);

    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => $type,
        'tokenable_id' => $identifiant,
        'name' => 'témoin',
        'token' => hash('sha256', Str::random(40)),
        'abilities' => '["*"]',
        'created_at' => $maintenant,
        'updated_at' => $maintenant,
    ]);

    DB::table('model_has_roles')->insert([
        'role_id' => Role::findOrCreate('temoin-de-suppression', 'web')->getKey(),
        'model_type' => $type,
        'model_id' => $identifiant,
    ]);

    DB::table('model_has_permissions')->insert([
        'permission_id' => Permission::findOrCreate('temoin-de-suppression', 'web')->getKey(),
        'model_type' => $type,
        'model_id' => $identifiant,
    ]);

    activity()->causedBy($proprietaire)->log('témoin : il en est la cause');
    activity()->performedOn($proprietaire)->log('témoin : il en est le sujet');
}

/**
 * Le nombre de lignes qui désignent encore ce propriétaire, par relation.
 *
 * Le filtre porte toujours sur le type ET l'identifiant, comme l'effacement
 * qu'il vérifie.
 *
 * @return array<string, int>
 */
function compteSupprimeSesTracesRestantes(string $type, int $identifiant): array
{
    $restantes = [];

    foreach (User::TRACES_POLYMORPHES as $trace) {
        [$table, $relation] = explode('.', $trace);

        $restantes[$trace] = DB::table($table)
            ->where("{$relation}_type", $type)
            ->where("{$relation}_id", $identifiant)
            ->count();
    }

    return $restantes;
}

/**
 * Un administrateur du panneau, autorisé à supprimer des comptes.
 */
function compteSupprimeParLePanneau(): void
{
    test()->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('User')), 'admin');
}

dataset('chemins de suppression du compte', [
    'le profil, par le compte lui-même' => [function (User $compte): void {
        test()->actingAs($compte)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');
    }],
    "le panneau, depuis la page d'édition" => [function (User $compte): void {
        compteSupprimeParLePanneau();

        Livewire::test(EditUser::class, ['record' => $compte->getKey()])
            ->callAction(DeleteAction::class);
    }],
    'le panneau, en suppression groupée' => [function (User $compte): void {
        compteSupprimeParLePanneau();

        Livewire::test(ListUsers::class)
            ->selectTableRecords([$compte->getKey()])
            ->callAction(TestAction::make('delete')->table()->bulk());
    }],
    'User::destroy(), pour une commande à venir' => [function (User $compte): void {
        User::destroy($compte->id);
    }],
    'deleteQuietly(), qui ne lève aucun évènement' => [function (User $compte): void {
        $compte->deleteQuietly();
    }],
]);

it('efface toutes les traces polymorphes du compte, et seulement les siennes', function (Closure $supprimer): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $compte = User::factory()->create();
    $identifiant = $compte->id;
    $voisin = User::factory()->create();

    // Le même identifiant, dans l'autre table d'identité.
    $jumeau = Admin::factory()->create(['id' => $identifiant]);

    foreach ([$compte, $voisin, $jumeau] as $proprietaire) {
        compteSupprimeSemerSesTraces($proprietaire);
    }

    $typeCompte = $compte->getMorphClass();

    expect(compteSupprimeSesTracesRestantes($typeCompte, $identifiant))
        ->each->toBeGreaterThan(0);

    $voisinAvant = compteSupprimeSesTracesRestantes($voisin->getMorphClass(), $voisin->id);
    $jumeauAvant = compteSupprimeSesTracesRestantes($jumeau->getMorphClass(), $identifiant);

    $supprimer($compte);

    expect(User::query()->whereKey($identifiant)->exists())->toBeFalse()
        ->and(compteSupprimeSesTracesRestantes($typeCompte, $identifiant))->each->toBe(0)
        ->and(compteSupprimeSesTracesRestantes($voisin->getMorphClass(), $voisin->id))->toBe($voisinAvant)
        ->and(compteSupprimeSesTracesRestantes($jumeau->getMorphClass(), $identifiant))->toBe($jumeauAvant);
})->with('chemins de suppression du compte');

/**
 * Le nombre de jetons de réinitialisation rangés sous cette adresse.
 */
function compteSupprimeSesJetonsDeReinitialisation(string $courriel): int
{
    return DB::table('password_reset_tokens')->where('email', $courriel)->count();
}

/*
 * `password_reset_tokens` désigne le compte par son adresse de courriel, sans
 * clé étrangère ni type : la ligne survivait au compte (#1938). Celle d'un
 * compte voisin doit rester, son jeton est peut-être en cours d'usage.
 */
it('efface le jeton de réinitialisation du compte, et seulement le sien', function (Closure $supprimer): void {
    $compte = User::factory()->create();
    $voisin = User::factory()->create();

    Password::createToken($compte);
    Password::createToken($voisin);

    expect(compteSupprimeSesJetonsDeReinitialisation($compte->email))->toBe(1);

    $supprimer($compte);

    expect(User::query()->whereKey($compte->id)->exists())->toBeFalse()
        ->and(compteSupprimeSesJetonsDeReinitialisation($compte->email))->toBe(0)
        ->and(compteSupprimeSesJetonsDeReinitialisation($voisin->email))->toBe(1);
})->with('chemins de suppression du compte');

/**
 * Une séance de ce compte : une ligne sur cet exercice, et une série faite.
 */
function compteSupprimeSemerUneSeance(User $compte, Exercise $exercice): void
{
    $seance = Workout::factory()->create(['user_id' => $compte->id]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 80, 'reps' => 5]);
}

/**
 * Ce que ce compte possède encore, par table.
 *
 * Lignes et séries se comptent par leur copie du propriétaire, que
 * `app:verify-data-coherence` tient chaque nuit égale à celui de la séance.
 *
 * @return array<string, int>
 */
function compteSupprimeSesDonneesRestantes(int $identifiant): array
{
    return [
        'séances' => DB::table('workouts')->where('user_id', $identifiant)->count(),
        'lignes de séance' => DB::table('workout_lines')->where('user_id', $identifiant)->count(),
        'séries' => DB::table('sets')->where('user_id', $identifiant)->count(),
        'exercices personnels' => DB::table('exercises')->where('user_id', $identifiant)->count(),
        'records' => DB::table('personal_records')->where('user_id', $identifiant)->count(),
    ];
}

/*
 * `workout_lines.exercise_id` protège un exercice utilisé par une clé sans
 * `ON DELETE` (#1982). La cascade de `users` vers `exercises` butait sur les
 * lignes de séance du compte, encore présentes : erreur 1451, transaction
 * annulée, compte et données restés en base, et 500 au profil.
 *
 * Le compte a un exercice personnel utilisé dans une séance avec une série,
 * et une séance sur un exercice global. Le voisin a les mêmes, sur le même
 * exercice global : rien des siens ne doit bouger, ni l'exercice global.
 */
it('efface ses séances et ses exercices personnels, même utilisés, et seulement les siens', function (Closure $supprimer): void {
    $compte = User::factory()->create();
    $voisin = User::factory()->create();
    $exerciceGlobal = Exercise::factory()->create();

    foreach ([$compte, $voisin] as $proprietaire) {
        compteSupprimeSemerUneSeance($proprietaire, Exercise::factory()->create(['user_id' => $proprietaire->id]));
        compteSupprimeSemerUneSeance($proprietaire, $exerciceGlobal);
    }

    expect(compteSupprimeSesDonneesRestantes($compte->id))->each->toBeGreaterThan(0);

    $voisinAvant = compteSupprimeSesDonneesRestantes($voisin->id);

    $supprimer($compte);

    expect(User::query()->whereKey($compte->id)->exists())->toBeFalse()
        ->and(compteSupprimeSesDonneesRestantes($compte->id))->each->toBe(0)
        ->and(compteSupprimeSesDonneesRestantes($voisin->id))->toBe($voisinAvant)
        ->and(Exercise::query()->whereKey($exerciceGlobal->id)->exists())->toBeTrue();
})->with('chemins de suppression du compte');

/*
 * Les séances partent dans `performDeleteOnModel()`, après l'évènement
 * `deleting` : un écouteur qui refuse la suppression la refuse avant qu'une
 * seule séance ne parte. Effacées en tête de `delete()`, elles auraient été
 * perdues pour un compte qui reste.
 */
it('garde les séances du compte quand un écouteur refuse sa suppression', function (): void {
    $compte = User::factory()->create();
    compteSupprimeSemerUneSeance($compte, Exercise::factory()->create(['user_id' => $compte->id]));
    $avant = compteSupprimeSesDonneesRestantes($compte->id);

    User::deleting(fn (User $supprime): bool => $supprime->isNot($compte));

    expect($compte->delete())->toBeFalse()
        ->and(User::query()->whereKey($compte->id)->exists())->toBeTrue()
        ->and(compteSupprimeSesDonneesRestantes($compte->id))->toBe($avant);
});

/*
 * Le correctif contourne la clé, il ne l'affaiblit pas : elle reste sans
 * `ON DELETE` (RESTRICT pour InnoDB, que le schéma de la base nomme
 * NO ACTION), et un exercice utilisé ne se supprime toujours ni par la page
 * des exercices, ni directement en base.
 */
it('garde la clé qui protège un exercice utilisé dans une séance', function (): void {
    $regle = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
        ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
        ->where('CONSTRAINT_NAME', 'workout_lines_exercise_id_foreign')
        ->value('DELETE_RULE');

    expect($regle)->toBeIn(['NO ACTION', 'RESTRICT']);

    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id]);
    compteSupprimeSemerUneSeance($compte, $exercice);

    $this->actingAs($compte)
        ->delete(route('exercises.destroy', $exercice))
        ->assertSessionHasErrors(['exercise']);

    expect(fn (): int => DB::table('exercises')->where('id', $exercice->id)->delete())
        ->toThrow(QueryException::class, 'workout_lines_exercise_id_foreign');

    expect(Exercise::query()->whereKey($exercice->id)->exists())->toBeTrue();
});

/*
 * Le profil supprime le compte AVANT de déconnecter (#1982). Déconnecté
 * d'abord, l'utilisateur sortait de sa session même quand la suppression
 * échouait, et pouvait croire effacé un compte resté en base. La panne
 * frappe ici la suppression de la ligne du compte.
 */
it('laisse connecté un utilisateur dont la suppression échoue', function (): void {
    $compte = User::factory()->create();

    DB::listen(function (QueryExecuted $requete): void {
        if (str_starts_with($requete->sql, 'delete from `users`')) {
            throw new RuntimeException('panne simulée');
        }
    });

    $this->actingAs($compte)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertServerError();

    $this->assertAuthenticatedAs($compte);

    expect(User::query()->whereKey($compte->id)->exists())->toBeTrue();
});

/*
 * Déconnecter APRÈS la suppression a un piège : `SessionGuard::logout()` fait
 * tourner le jeton « se souvenir de moi » par un `save()` du compte, et
 * `save()` sur un modèle supprimé l'insère de nouveau. Le profil oublie le
 * jeton en mémoire avant de déconnecter. Le compte ne revient pas, et le
 * témoin du navigateur est bien retiré.
 */
it('ne réinsère pas le compte en déconnectant un utilisateur venu par « se souvenir de moi »', function (): void {
    $compte = User::factory()->create(['remember_token' => Str::random(60)]);
    $garde = Auth::guard('web');

    if (! $garde instanceof SessionGuard) {
        throw new LogicException('La garde des comptes tient sa connexion en session.');
    }

    $temoin = $garde->getRecallerName();

    $this->actingAs($compte)
        ->withCookie($temoin, $compte->id.'|'.$compte->getRememberToken().'|'.$compte->getAuthPassword())
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/')
        ->assertCookieExpired($temoin);

    $this->assertGuest();

    expect(User::query()->whereKey($compte->id)->exists())->toBeFalse();
});

/*
 * La table `sessions` reste hors de l'effacement (#1938). Sa colonne `user_id`
 * reçoit l'identifiant de la garde par défaut au moment où la session
 * s'écrit, et le panneau fait de la garde `admin` celle de ses requêtes : la
 * ligne d'un administrateur porte donc son identifiant d'administrateur, sans
 * type, là où celle d'un compte porte le sien. Effacer par `user_id`
 * déconnecterait l'administrateur qui partage l'identifiant du compte
 * supprimé. La première assertion vérifie cette prémisse : si elle tombe, la
 * colonne ne désigne plus que des comptes, et la question se rouvre.
 */
it("garde la session de l'administrateur qui porte l'identifiant du compte supprimé", function (): void {
    config(['session.driver' => 'database']);

    $administrateur = FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('User'));
    $identifiant = $administrateur->id;
    $compte = User::factory()->create(['id' => $identifiant]);
    $garde = Auth::guard('admin');

    if (! $garde instanceof SessionGuard) {
        throw new LogicException('La garde du panneau tient sa connexion en session.');
    }

    // La seule session : celle que l'administrateur ouvre au panneau, comme
    // après la page de connexion. Le compte ne s'est jamais connecté.
    $this->withSession([
        $garde->getName() => $administrateur->getAuthIdentifier(),
        'password_hash_admin' => $garde->hashPasswordForCookie($administrateur->getAuthPassword()),
    ])->get('/backoffice')->assertOk();

    expect(DB::table('sessions')->where('user_id', $identifiant)->count())->toBe(1);

    $compte->delete();

    expect(User::query()->whereKey($identifiant)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $identifiant)->count())->toBe(1);
});

/*
 * La suppression du compte et l'effacement de ses traces forment une seule
 * transaction : si l'effacement échoue en route, le compte reste, et avec lui
 * tout ce qu'il désigne. Sans quoi une panne au milieu laisserait un compte
 * disparu et des lignes orphelines que plus rien ne viendrait chercher.
 *
 * La panne frappe la DERNIÈRE table de l'inventaire : les précédentes, le
 * jeton de réinitialisation et les séances (#1982) ont déjà été effacés, et
 * c'est leur retour qui prouve la transaction.
 */
it("garde le compte et toutes ses traces quand l'effacement échoue en route", function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $compte = User::factory()->create();
    compteSupprimeSemerSesTraces($compte);
    compteSupprimeSemerUneSeance($compte, Exercise::factory()->create(['user_id' => $compte->id]));
    Password::createToken($compte);

    $avant = compteSupprimeSesTracesRestantes($compte->getMorphClass(), $compte->id);
    $donneesAvant = compteSupprimeSesDonneesRestantes($compte->id);
    $derniereTable = Str::before(array_last(User::TRACES_POLYMORPHES), '.');

    DB::listen(function (QueryExecuted $requete) use ($derniereTable): void {
        if (str_starts_with($requete->sql, "delete from `{$derniereTable}`")) {
            throw new RuntimeException('panne simulée');
        }
    });

    expect(fn (): ?bool => $compte->delete())->toThrow(RuntimeException::class, 'panne simulée');

    // Le compte qui reste journalise de nouveau ses changements.
    expect(User::query()->whereKey($compte->id)->exists())->toBeTrue()
        ->and(compteSupprimeSesTracesRestantes($compte->getMorphClass(), $compte->id))->toBe($avant)
        ->and(compteSupprimeSesJetonsDeReinitialisation($compte->email))->toBe(1)
        ->and(compteSupprimeSesDonneesRestantes($compte->id))->toBe($donneesAvant)
        ->and($compte->enableLoggingModelsEvents)->toBeTrue();
});

/*
 * L'entrée « deleted » du journal recopierait le nom et le courriel du compte
 * au moment même où on les efface. L'effacement la rattraperait aujourd'hui,
 * parce qu'il passe après elle ; mais avec le tampon du paquet activé
 * (`activitylog.buffer.enabled`), elle ne serait écrite qu'à la fin de la
 * requête, APRÈS l'effacement, et resterait. Rien ne doit donc l'écrire.
 */
it("n'écrit rien dans le journal d'activité en supprimant le compte", function (): void {
    $compte = User::factory()->create();
    $ecritures = 0;

    DB::listen(function (QueryExecuted $requete) use (&$ecritures): void {
        if (str_starts_with($requete->sql, 'insert into `activity_log`')) {
            $ecritures++;
        }
    });

    $compte->delete();

    expect($ecritures)->toBe(0);
});

/*
 * Quand un administrateur supprime un compte depuis le panneau, une entrée
 * d'audit le dit (#1938) : l'entrée « deleted » du compte, effacée avec lui,
 * ne laissait aucune trace de l'action. Elle ne dit que cela : l'administrateur
 * en est la cause, elle n'a pas de sujet, et rien du compte n'y figure.
 */
dataset('suppressions du compte par un administrateur', [
    "la page d'édition" => [function (User $compte): void {
        Livewire::test(EditUser::class, ['record' => $compte->getKey()])
            ->callAction(DeleteAction::class);
    }],
    'la suppression groupée' => [function (User $compte): void {
        Livewire::test(ListUsers::class)
            ->selectTableRecords([$compte->getKey()])
            ->callAction(TestAction::make('delete')->table()->bulk());
    }],
]);

/**
 * Un administrateur qui peut supprimer des comptes et lire le journal
 * d'audit, connecté au panneau : un seul `actingAs` par cas.
 */
function compteSupprimeLAdministrateurConnecte(): Admin
{
    $administrateur = FilamentAdminPanel::admin([
        ...FilamentAdminPanel::crudPermissions('User'),
        'ViewAny:ActivityLog',
    ]);

    test()->actingAs($administrateur, 'admin');

    return $administrateur;
}

/**
 * L'identifiant de la dernière entrée d'audit, zéro quand il n'y en a pas.
 */
function compteSupprimeLaDerniereEntreeDAudit(): int
{
    $derniere = ActivityLog::query()->max('id');

    return is_numeric($derniere) ? (int) $derniere : 0;
}

/**
 * Les entrées d'audit écrites après celle-ci.
 *
 * @return \Illuminate\Database\Eloquent\Collection<int, ActivityLog>
 */
function compteSupprimeLesEntreesDAuditApres(int $derniere): \Illuminate\Database\Eloquent\Collection
{
    return ActivityLog::query()->where('id', '>', $derniere)->orderBy('id')->get();
}

it("laisse une entrée d'audit anonyme quand un administrateur le supprime", function (Closure $supprimer): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $administrateur = compteSupprimeLAdministrateurConnecte();
    $compte = User::factory()->create([
        'name' => 'Nom Temoin Anonyme',
        'email' => 'temoin-anonyme@example.org',
    ]);
    compteSupprimeSemerSesTraces($compte);
    $derniere = compteSupprimeLaDerniereEntreeDAudit();

    $supprimer($compte);

    $entrees = compteSupprimeLesEntreesDAuditApres($derniere);

    expect(User::query()->whereKey($compte->id)->exists())->toBeFalse()
        ->and(compteSupprimeSesTracesRestantes($compte->getMorphClass(), $compte->id))->each->toBe(0)
        ->and($entrees)->toHaveCount(1);

    $entree = $entrees->sole();
    $ligne = (string) json_encode(DB::table('activity_log')->find($entree->id), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect($entree->description)->toBe(User::SUPPRESSION_PAR_UN_ADMINISTRATEUR)
        ->and($entree->event)->toBe('deleted')
        ->and($entree->causer_type)->toBe($administrateur->getMorphClass())
        ->and($entree->causer_id)->toBe($administrateur->id)
        ->and($entree->subject_type)->toBeNull()
        ->and($entree->subject_id)->toBeNull()
        ->and($entree->properties?->all())->toBe([])
        ->and($entree->attribute_changes?->all())->toBe([])
        ->and($ligne)->not->toContain('Nom Temoin Anonyme')
        ->and($ligne)->not->toContain('temoin-anonyme@example.org');
})->with('suppressions du compte par un administrateur');

/*
 * L'entrée anonyme s'écrit APRÈS l'effacement des traces du compte qu'elle
 * concerne : cet effacement-là ne peut pas l'atteindre. Le risque est celui
 * d'un compte supprimé plus tard qui porte l'identifiant de l'administrateur
 * (`users` et `admins` ont chacune leur séquence) : l'effacement de
 * `activity_log.causer`, s'il filtrait sur le seul identifiant, emporterait
 * alors l'entrée, et avec elle toute trace de la suppression.
 */
it("garde l'entrée anonyme quand part ensuite un compte qui porte l'identifiant de l'administrateur", function (): void {
    $administrateur = compteSupprimeLAdministrateurConnecte();
    $compte = User::factory()->create();
    $derniere = compteSupprimeLaDerniereEntreeDAudit();

    Livewire::test(EditUser::class, ['record' => $compte->getKey()])
        ->callAction(DeleteAction::class);

    $entree = compteSupprimeLesEntreesDAuditApres($derniere)->sole();
    $homonyme = User::factory()->create(['id' => $administrateur->id]);

    $this->actingAs($homonyme)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    expect(User::query()->whereKey($homonyme->id)->exists())->toBeFalse()
        ->and($entree->causer_id)->toBe($homonyme->id)
        ->and(compteSupprimeLesEntreesDAuditApres($derniere)->modelKeys())->toBe([$entree->id]);
});

it('écrit une entrée anonyme par compte supprimé en suppression groupée', function (): void {
    $administrateur = compteSupprimeLAdministrateurConnecte();
    $comptes = User::factory()->count(2)->create();
    $derniere = compteSupprimeLaDerniereEntreeDAudit();

    Livewire::test(ListUsers::class)
        ->selectTableRecords($comptes->modelKeys())
        ->callAction(TestAction::make('delete')->table()->bulk());

    $entrees = compteSupprimeLesEntreesDAuditApres($derniere);

    expect(User::query()->whereKey($comptes->modelKeys())->exists())->toBeFalse()
        ->and($entrees)->toHaveCount(2)
        ->and($entrees->pluck('description')->unique()->all())->toBe([User::SUPPRESSION_PAR_UN_ADMINISTRATEUR])
        ->and($entrees->pluck('causer_id')->unique()->all())->toBe([$administrateur->id])
        ->and($entrees->whereNotNull('subject_type'))->toBeEmpty();
});

/*
 * Le journal d'audit du panneau affichait « # » dans la colonne du sujet
 * d'une entrée qui n'en a pas.
 */
it("affiche l'entrée anonyme dans le journal d'audit du panneau", function (): void {
    $administrateur = compteSupprimeLAdministrateurConnecte();
    $compte = User::factory()->create();
    $derniere = compteSupprimeLaDerniereEntreeDAudit();

    Livewire::test(EditUser::class, ['record' => $compte->getKey()])
        ->callAction(DeleteAction::class);

    $entree = compteSupprimeLesEntreesDAuditApres($derniere)->sole();

    Livewire::test(ListActivityLogs::class)
        ->assertCanSeeTableRecords([$entree])
        ->assertTableColumnStateSet('description', User::SUPPRESSION_PAR_UN_ADMINISTRATEUR, $entree)
        ->assertTableColumnStateSet('subject', 'aucun', $entree)
        ->assertTableColumnStateSet('causer', 'Admin #'.$administrateur->id, $entree);
});

/*
 * Le compte qui se supprime lui-même depuis son profil n'a pas
 * d'administrateur pour acteur : rien ne s'écrit, comme hors de toute
 * requête (`User::destroy()`, une commande).
 */
it("n'écrit aucune entrée d'audit quand le compte se supprime lui-même", function (): void {
    $compte = User::factory()->create();
    $derniere = compteSupprimeLaDerniereEntreeDAudit();

    $this->actingAs($compte)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');

    expect(User::query()->whereKey($compte->id)->exists())->toBeFalse()
        ->and(compteSupprimeLesEntreesDAuditApres($derniere))->toBeEmpty();
});

/**
 * Une erreur rapportée par le navigateur de ce compte.
 */
function compteSupprimeSemerUneErreurDuNavigateur(User $compte): void
{
    ErreurNavigateur::query()->create([
        'user_id' => $compte->id,
        'type' => 'error',
        'message' => 'témoin du compte '.$compte->id,
        'url' => '/workouts/'.$compte->id,
        'empreinte' => ErreurNavigateur::empreinteDe('error', 'témoin', null, null),
    ]);
}

/*
 * Les erreurs du navigateur portent le message et l'URL de la page, qui
 * peuvent recopier ce que le compte a saisi. Leur clé vidait `user_id` à la
 * suppression du compte (`ON DELETE SET NULL`) : la ligne restait jusqu'à la
 * purge à trente jours. Elle part désormais avec le compte (#1938).
 */
it('efface les erreurs du navigateur du compte, et seulement les siennes', function (Closure $supprimer): void {
    $compte = User::factory()->create();
    $voisin = User::factory()->create();

    compteSupprimeSemerUneErreurDuNavigateur($compte);
    compteSupprimeSemerUneErreurDuNavigateur($voisin);

    $supprimer($compte);

    expect(User::query()->whereKey($compte->id)->exists())->toBeFalse()
        ->and(ErreurNavigateur::query()->count())->toBe(1)
        ->and(ErreurNavigateur::query()->where('user_id', $voisin->id)->count())->toBe(1);
})->with('chemins de suppression du compte');

/*
 * Toute ligne qui désigne un compte par une clé étrangère part avec lui :
 * `SET NULL` garderait la ligne, sans nom, mais avec ce qu'elle dit du compte.
 * Une table qui doit survivre au compte le justifie ici. Les règles se lisent
 * par contrainte, et non par table : une table qui porterait deux clés vers
 * `users` ne garderait sinon que la règle de l'une.
 */
it('efface en cascade toute ligne qui désigne le compte par une clé étrangère', function (): void {
    $regles = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
        ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
        ->where('REFERENCED_TABLE_NAME', 'users')
        ->pluck('DELETE_RULE', 'CONSTRAINT_NAME')
        ->all();

    expect($regles)->toHaveKey('erreurs_navigateur_user_id_foreign')
        ->and($regles)->toHaveKey('filament_exceptions_table_user_id_foreign')
        ->and(array_filter($regles, static fn (mixed $regle): bool => $regle !== 'CASCADE'))->toBe([]);
});

/**
 * Une exception levée par une requête de ce compte, comme le serveur la garde.
 */
function compteSupprimeLeverUneException(User $compte): void
{
    test()->actingAs($compte)->post('/_boum-du-compte')->assertServerError();
}

/*
 * Une exception levée par une requête du compte porte son identifiant, et
 * souvent ce qu'il a saisi (en-têtes, modèle lié par la route, message) : elle
 * part avec lui (#1938). Celle qu'aucun compte connecté n'a levée n'a pas de
 * lien : elle reste, jusqu'à la purge à trente jours.
 */
it('efface les exceptions levées par le compte, et seulement les siennes', function (Closure $supprimer): void {
    ExceptionEnregistree::query()->create([
        'type' => RuntimeException::class,
        'message' => 'sans compte',
        'file' => __FILE__,
        'line' => 1,
        'trace' => [],
        'method' => 'GET',
        'path' => '/',
    ]);

    Route::middleware(['web', 'auth'])->post('/_boum-du-compte', function (): never {
        throw new RuntimeException('exception du compte');
    });

    $compte = User::factory()->create();
    $voisin = User::factory()->create();

    compteSupprimeLeverUneException($compte);
    compteSupprimeLeverUneException($voisin);

    expect(ExceptionEnregistree::query()->where('user_id', $compte->id)->count())->toBe(1);

    $supprimer($compte);

    expect(User::query()->whereKey($compte->id)->exists())->toBeFalse()
        ->and(ExceptionEnregistree::query()->where('user_id', $compte->id)->count())->toBe(0)
        ->and(ExceptionEnregistree::query()->where('user_id', $voisin->id)->count())->toBe(1)
        ->and(ExceptionEnregistree::query()->whereNull('user_id')->count())->toBe(1);
})->with('chemins de suppression du compte');

/*
 * Avant #1670 (2026-09-03), le journal d'activité suivait aussi les données
 * métier : une entrée sur une séance du compte recopiait ses champs et son
 * `user_id`. Celles dont la cause est un administrateur ou personne ne
 * désignent le compte ni comme cause, ni comme sujet : elles survivent à sa
 * suppression, jusqu'à la purge de l'audit à 180 jours, donc jusqu'au début
 * de mars 2027 pour les dernières, selon la date à laquelle #1670 a été
 * déployé (#1938). La page de profil annonce cette durée :
 * elle se raccourcit le jour où ces entrées partent avec le compte, ou que la
 * purge les prend plus tôt.
 */
it("laisse les entrées d'audit d'avant #1670 sur les données du compte à la purge à 180 jours, que la page de profil annonce", function (): void {
    $compte = User::factory()->create();
    $seance = Workout::withoutEvents(static fn (): Workout => Workout::factory()->create(['user_id' => $compte->id]));

    foreach ([Admin::factory()->create(), null] as $cause) {
        DB::table('activity_log')->insert([
            'log_name' => 'default',
            'description' => 'updated',
            'event' => 'updated',
            'subject_type' => $seance->getMorphClass(),
            'subject_id' => $seance->id,
            'causer_type' => $cause?->getMorphClass(),
            'causer_id' => $cause?->id,
            'attribute_changes' => json_encode(['attributes' => ['user_id' => $compte->id, 'notes' => 'note témoin']]),
            'created_at' => '2026-09-02 12:00:00',
            'updated_at' => '2026-09-02 12:00:00',
        ]);
    }

    $compte->delete();

    $purges = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $evenement): bool => str_contains((string) $evenement->command, 'activitylog:clean'),
    ));
    $promesse = preg_replace('/\s+/', ' ', (string) file_get_contents(resource_path('js/Pages/Profile/Partials/DeleteUserForm.vue')));

    expect(DB::table('activity_log')->where('subject_type', $seance->getMorphClass())->where('subject_id', $seance->id)->count())->toBe(2)
        ->and($purges)->toHaveCount(1)
        ->and((string) $purges[0]->command)->toContain('--days=180')
        ->and($promesse)->toContain("180 jours dans l'historique d'audit");
});

/*
 * La pile écrit chaque ligne de journal sur stderr (`LOG_STACK`), donc dans
 * `docker logs`. Le gestionnaire d'exceptions y ajoute l'identifiant du compte
 * connecté, et le message d'une erreur SQL ce qu'il a saisi. Aucun service n'y
 * posait de rotation, et cette copie vivait autant que le conteneur ; app,
 * worker et scheduler la bornent désormais par la taille (`logging`, pilote
 * `json-file`, `max-size` et `max-file`, #1938). Une rotation par taille ne
 * donne aucune durée. La page de profil promet quatorze jours aux journaux du
 * serveur, que tient la purge du volume `journaux` (#2053), et dit de cette
 * seconde copie, bornée par sa taille, qu'elle n'a pas de durée garantie : de
 * nouvelles lignes en remplacent les traces. Elle ne dit plus des journaux du
 * serveur, tous confondus, qu'ils n'ont d'autre durée que ce remplacement.
 * `LesJournauxDesConteneursSontBornesTest` tient les valeurs du réglage.
 */
it('promet quatorze jours aux journaux du serveur, et aucune durée à la copie de docker logs, que sa rotation par taille ne borne pas dans le temps', function (): void {
    $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));
    assert(is_array($composition) && is_array($composition['services'] ?? null));
    $promesse = preg_replace('/\s+/', ' ', (string) file_get_contents(resource_path('js/Pages/Profile/Partials/DeleteUserForm.vue')));

    foreach (['app', 'worker', 'scheduler'] as $nom) {
        $service = $composition['services'][$nom] ?? null;
        assert(is_array($service) && is_array($service['environment'] ?? null));

        $pile = $service['environment']['LOG_STACK'] ?? null;

        expect(is_string($pile) ? $pile : '')->toContain('stderr')
            ->and(data_get($service, 'logging.driver'))->toBe('json-file', "`{$nom}` ne pose pas la rotation de docker logs.")
            ->and(data_get($service, 'logging.options'))->toBeArray()->toHaveKeys(['max-size', 'max-file']);
    }

    expect($promesse)->toContain('quatorze jours dans les journaux du serveur')
        ->and($promesse)->toContain('Une seconde copie des journaux, bornée par sa taille, peut aussi en garder des traces sans durée garantie, le temps que de nouvelles lignes les remplacent.')
        ->and($promesse)->not->toContain('Les journaux du serveur peuvent aussi en garder des traces');
});

/**
 * Le nom du fichier du jour du conteneur `app`, tant de jours avant ce jour.
 */
function compteSupprimeLeFichierDuJour(DateTimeImmutable $aujourdhui, int $jours): string
{
    return 'app-'.$aujourdhui->modify("-{$jours} days")->format('Y-m-d').'.log';
}

/**
 * Les fichiers du jour d'un dossier de journaux, par ordre alphabétique.
 *
 * @return list<string>
 */
function compteSupprimeLesFichiersDuJour(string $dossier): array
{
    $fichiers = glob($dossier.'/app-*.log');
    assert(is_array($fichiers));

    $restants = array_map(basename(...), $fichiers);
    sort($restants);

    return $restants;
}

/**
 * Les fichiers du jour qui restent dans un dossier de journaux, une fois
 * semés ceux de jours passés et écrite une ligne aujourd'hui, par le canal
 * `daily` tel que la configuration le construit (le dossier et le niveau
 * mis à part). Monolog date ses fichiers à l'horloge du système, pas à
 * celle de Carbon : les jours passés se comptent depuis cette horloge.
 *
 * @param  list<int>  $joursPasses
 * @return list<string>
 */
function compteSupprimeLesFichiersDuJourRestants(string $dossier, DateTimeImmutable $aujourdhui, array $joursPasses): array
{
    foreach ($joursPasses as $jours) {
        file_put_contents($dossier.'/'.compteSupprimeLeFichierDuJour($aujourdhui, $jours), "trace\n");
    }

    $canal = config('logging.channels.daily');
    assert(is_array($canal));

    Log::build([...$canal, 'path' => $dossier.'/app.log', 'level' => 'debug'])->info('nouvelle ligne');

    return compteSupprimeLesFichiersDuJour($dossier);
}

/**
 * Les fichiers du jour qui restent dans ce dossier de journaux après la
 * purge planifiée, lancée sur lui.
 *
 * @return list<string>
 */
function compteSupprimeLesFichiersDuJourApresLaPurge(string $dossier): array
{
    config(['logging.channels.daily.path' => $dossier.'/app.log']);

    expect(Artisan::call('journaux:purger'))->toBe(0, Artisan::output());

    return compteSupprimeLesFichiersDuJour($dossier);
}

/*
 * La pile écrit aussi chaque ligne dans le fichier du jour du conteneur, sur
 * le volume `journaux` (`LOG_STACK: stderr,daily`). Le canal `daily` y garde
 * les quatorze fichiers les plus récents de chaque conteneur, pas quatorze
 * jours de calendrier : Monolog n'efface le plus ancien qu'en ouvrant le
 * fichier d'un nouveau jour, et seulement s'il y en a plus de quatorze. Un
 * conteneur qui écrit peu (`LOG_LEVEL: info`) gardait ainsi un fichier de
 * soixante jours, et ses traces d'un compte supprimé, tant que quatorze jours
 * plus récents n'avaient pas été écrits (#1938). La purge planifiée
 * (`journaux:purger`, #2053) les efface chaque nuit à quatorze jours de
 * calendrier, et le README le dit : il ne parle plus des « quatorze derniers
 * jours où le conteneur a écrit ». La prémisse reste vérifiée : le jour où
 * Monolog bornerait lui-même par la durée, la purge deviendrait redondante.
 */
it('efface du volume `journaux` ce que le canal `daily` y garde au-delà de quatorze jours de calendrier, comme le dit le README', function (): void {
    $dossier = sys_get_temp_dir().'/journaux-du-jour-'.bin2hex(random_bytes(6));
    $aujourdhui = new DateTimeImmutable();
    $readme = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path('README.md')));

    try {
        File::ensureDirectoryExists($dossier.'/peu');
        File::ensureDirectoryExists($dossier.'/chaque-jour');

        $peu = compteSupprimeLesFichiersDuJourRestants($dossier.'/peu', $aujourdhui, [60, 40, 20]);
        $chaqueJour = compteSupprimeLesFichiersDuJourRestants($dossier.'/chaque-jour', $aujourdhui, [60, ...range(1, 13)]);

        expect(config('logging.channels.daily.days'))->toEqual(14)
            ->and($peu)->toHaveCount(4)
            ->and($peu)->toContain(...array_map(static fn (int $jours): string => compteSupprimeLeFichierDuJour($aujourdhui, $jours), [60, 40, 20]))
            ->and($chaqueJour)->toHaveCount(14)
            ->and($chaqueJour)->not->toContain(compteSupprimeLeFichierDuJour($aujourdhui, 60))
            ->and($chaqueJour)->toContain(compteSupprimeLeFichierDuJour($aujourdhui, 13));

        expect(compteSupprimeLesFichiersDuJourApresLaPurge($dossier.'/peu'))->toBe([compteSupprimeLeFichierDuJour($aujourdhui, 0)])
            ->and(compteSupprimeLesFichiersDuJourApresLaPurge($dossier.'/chaque-jour'))->toBe($chaqueJour)
            ->and(str_contains($readme, 'quatorze jours dans le volume `journaux`'))
            ->toBeTrue('Le README doit dire ce que garde le volume `journaux` : quatorze jours, que tient la purge planifiée.')
            ->and(str_contains($readme, '`journaux:purger`'))
            ->toBeTrue('Le README doit nommer la purge planifiée qui borne le volume `journaux`.')
            ->and(preg_match_all('/derniers jours où le conteneur a écrit|pas quatorze jours de calendrier/', $readme))
            ->toBe(0, 'Le README dit encore que le volume `journaux` garde les quatorze derniers jours où le conteneur a écrit : la purge planifiée le borne à quatorze jours de calendrier.');
    } finally {
        File::deleteDirectory($dossier);
    }
});
