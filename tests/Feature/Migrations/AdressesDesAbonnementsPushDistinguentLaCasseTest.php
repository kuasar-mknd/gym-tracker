<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\PushSubscription;

/*
 * L'adresse d'un abonnement push est en `ascii_bin`
 * (`database/migrations/2026_11_19_100000_distinguer_la_casse_des_adresses_des_abonnements_push.php`) :
 * une variante en majuscules n'est plus la même adresse. Le passage se joue par
 * des ordres DDL, qui valident la transaction de `RefreshDatabase` : ce fichier
 * fait lui-même ce que le `migrate:fresh` qui suivrait referait
 * (`.ai/rules/tests.md`).
 */

function adressesPushMigration(): Migration
{
    $migration = require database_path('migrations/2026_11_19_100000_distinguer_la_casse_des_adresses_des_abonnements_push.php');
    assert($migration instanceof Migration);

    return $migration;
}

function adressesPushMonter(): void
{
    $migration = adressesPushMigration();
    assert(method_exists($migration, 'up'));

    $migration->up();
}

function adressesPushDescendre(): void
{
    $migration = adressesPushMigration();
    assert(method_exists($migration, 'down'));

    $migration->down();
}

function adressesPushCollation(): string
{
    $colonne = collect(Schema::getColumns('push_subscriptions'))->firstWhere('name', 'endpoint');
    assert(is_array($colonne) && is_string($colonne['collation']));

    return $colonne['collation'];
}

function adressesPushEcrire(User $compte, string $adresse): void
{
    DB::table('push_subscriptions')->insert([
        'subscribable_type' => User::class,
        'subscribable_id' => $compte->id,
        'endpoint' => $adresse,
    ]);
}

afterEach(function (): void {
    if (DB::getPdo()->inTransaction()) {
        return;
    }

    Schema::withoutForeignKeyConstraints(function (): void {
        foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $table) {
            if ($table !== 'migrations') {
                DB::table($table)->delete();
            }
        }
    });

    adressesPushMonter();
    DB::getPdo()->beginTransaction();
});

it('compare l’adresse octet par octet', function (): void {
    expect(adressesPushCollation())->toBe('ascii_bin')
        ->and(Schema::hasIndex('push_subscriptions', ['endpoint'], 'unique'))->toBeTrue();
});

it('tient une adresse et sa variante en majuscules pour deux adresses', function (): void {
    $compte = User::factory()->create();
    adressesPushEcrire($compte, 'https://push.example/send/AbC');
    adressesPushEcrire($compte, 'https://push.example/send/ABC');

    expect(PushSubscription::findByEndpoint('https://push.example/send/ABC'))->not->toBeNull()
        ->and(PushSubscription::findByEndpoint('https://push.example/send/abc'))->toBeNull()
        ->and(fn () => adressesPushEcrire($compte, 'https://push.example/send/ABC'))->toThrow(UniqueConstraintViolationException::class);
});

it('ne lance aucun ordre sur une base déjà passée', function (): void {
    DB::flushQueryLog();
    DB::enableQueryLog();

    adressesPushMonter();

    $ordres = array_filter(DB::getQueryLog(), static fn (array $requete): bool => str_starts_with(mb_strtolower((string) $requete['query']), 'alter table'));
    DB::disableQueryLog();

    expect($ordres)->toBe([]);
});

it('passe en ascii_bin une base d’avant, l’index unique gardé, et rend sa collation à down()', function (): void {
    adressesPushDescendre();

    expect(adressesPushCollation())->toBe('ascii_general_ci')
        ->and(Schema::hasIndex('push_subscriptions', ['endpoint'], 'unique'))->toBeTrue();

    adressesPushMonter();

    expect(adressesPushCollation())->toBe('ascii_bin')
        ->and(Schema::hasIndex('push_subscriptions', ['endpoint'], 'unique'))->toBeTrue();
});

it('arrête down() sur deux adresses que seule la casse distingue', function (): void {
    $compte = User::factory()->create();
    adressesPushEcrire($compte, 'https://push.example/send/AbC');
    adressesPushEcrire($compte, 'https://push.example/send/abc');

    expect(adressesPushDescendre(...))->toThrow(UniqueConstraintViolationException::class)
        ->and(adressesPushCollation())->toBe('ascii_bin');
});
