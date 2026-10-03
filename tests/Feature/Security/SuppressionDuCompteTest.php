<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Admin;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
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

/*
 * La suppression du compte et l'effacement de ses traces forment une seule
 * transaction : si l'effacement échoue en route, le compte reste, et avec lui
 * tout ce qu'il désigne. Sans quoi une panne au milieu laisserait un compte
 * disparu et des lignes orphelines que plus rien ne viendrait chercher.
 *
 * La panne frappe la DERNIÈRE table de l'inventaire : les précédentes ont déjà
 * été vidées, et c'est leur retour qui prouve la transaction.
 */
it("garde le compte et toutes ses traces quand l'effacement échoue en route", function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $compte = User::factory()->create();
    compteSupprimeSemerSesTraces($compte);

    $avant = compteSupprimeSesTracesRestantes($compte->getMorphClass(), $compte->id);
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
