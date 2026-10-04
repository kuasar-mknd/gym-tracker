<?php

declare(strict_types=1);

use App\Models\TachePlanifiee;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/*
 * Un jeton de réinitialisation du mot de passe expire au bout de 60 minutes,
 * mais sa ligne de `password_reset_tokens`, rangée sous l'adresse de courriel,
 * restait en base indéfiniment (#1938) : aucune tâche ne lançait
 * `auth:clear-resets`. Elle tourne désormais chaque nuit, sous le moniteur des
 * tâches, qui dit quand elle échoue ou cesse de tourner.
 *
 * Une commande planifiée qui demande confirmation en production s'annule,
 * faute de quelqu'un pour répondre (`activitylog:clean` n'a ainsi jamais
 * purgé le journal) : la commande est donc jouée ici telle que le
 * planificateur la lance, en production.
 */

/**
 * La seule tâche planifiée qui lance `auth:clear-resets`.
 */
function jetonsDeReinitialisationLaTache(): Event
{
    $taches = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $evenement): bool => str_contains((string) $evenement->command, 'auth:clear-resets'),
    ));

    expect($taches)->toHaveCount(1, "Aucune tâche planifiée ne purge les jetons de réinitialisation expirés : leurs lignes restent en base, sous l'adresse de courriel.");

    return $taches[0];
}

/**
 * Le nombre de jetons rangés sous l'adresse de ce compte.
 */
function jetonsDeReinitialisationDe(User $compte): int
{
    return DB::table('password_reset_tokens')->where('email', $compte->email)->count();
}

it('purge chaque nuit les jetons expirés, sous le moniteur des tâches', function (): void {
    $tache = jetonsDeReinitialisationLaTache();

    expect($tache->expression)->toMatch('/^\d+ \d+ \* \* \*$/');

    // Le moniteur ne relit que les tâches qui ne portent pas `doNotMonitor()`.
    expect(Artisan::call('schedule-monitor:sync'))->toBe(0)
        ->and(TachePlanifiee::query()->where('name', 'auth:clear-resets')->exists())->toBeTrue();
});

it('tourne en production sans demander de confirmation, et ne purge que les jetons expirés', function (): void {
    $expire = User::factory()->create();
    $valide = User::factory()->create();

    Password::createToken($expire);
    $this->travel(61)->minutes();
    Password::createToken($valide);

    expect(jetonsDeReinitialisationDe($expire))->toBe(1)
        ->and(jetonsDeReinitialisationDe($valide))->toBe(1);

    // La ligne que le planificateur exécute, sans le binaire de PHP ni `artisan`.
    $commande = Str::after((string) jetonsDeReinitialisationLaTache()->command, "'artisan' ");

    app()->detectEnvironment(static fn (): string => 'production');
    $codeDeSortie = Artisan::call($commande);

    expect($codeDeSortie)->toBe(0, Artisan::output())
        ->and(jetonsDeReinitialisationDe($expire))->toBe(0)
        ->and(jetonsDeReinitialisationDe($valide))->toBe(1);
});
