<?php

declare(strict_types=1);

use App\Models\TachePlanifiee;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Une tâche en file qui échoue est recopiée dans `failed_jobs`, charge
 * comprise : la notification et son destinataire sérialisés, donc des
 * adresses de courriel en clair (`AdresseDuCompteChangee` y porte l'ancienne
 * et la nouvelle). Aucune tâche ne purgeait la table, et rien ne la relie à
 * un compte hors de cette charge : ces lignes restaient sans limite, compte
 * supprimé ou non (#1938). Elles partent désormais à trente jours, chaque
 * nuit, sous le moniteur des tâches, et la page de profil le promet.
 *
 * La commande est jouée ici telle que le planificateur la lance, en
 * production : une commande qui y demanderait confirmation s'annulerait,
 * faute de quelqu'un pour répondre.
 */

/**
 * La seule tâche planifiée qui lance `queue:prune-failed`.
 */
function tachesEnEchecLaPurge(): Event
{
    $taches = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $evenement): bool => str_contains((string) $evenement->command, 'queue:prune-failed'),
    ));

    expect($taches)->toHaveCount(1, 'Aucune tâche planifiée ne purge les tâches en échec : leurs charges, adresses de courriel comprises, restent en base sans limite.');

    return $taches[0];
}

/**
 * Consigne une tâche en échec, comme un worker le fait, à cet instant.
 */
function tachesEnEchecConsigner(string $temoin, Carbon $instant): void
{
    Carbon::setTestNow($instant);

    $consignation = app('queue.failer');
    assert($consignation instanceof FailedJobProviderInterface);

    $consignation->log('redis', 'default', (string) json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => $temoin,
        'data' => ['destinataire' => $temoin.'@example.org'],
    ]), new RuntimeException('échec témoin'));
}

/**
 * Les noms des tâches en échec encore en base.
 *
 * @return list<string>
 */
function tachesEnEchecRestantes(): array
{
    $restantes = [];

    foreach (DB::table('failed_jobs')->orderBy('failed_at')->pluck('payload') as $charge) {
        $decodee = is_string($charge) ? json_decode($charge, true) : null;
        $restantes[] = is_array($decodee) && is_string($decodee['displayName'] ?? null) ? $decodee['displayName'] : '';
    }

    return $restantes;
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('purge chaque nuit les tâches en échec, sous le moniteur des tâches', function (): void {
    $tache = tachesEnEchecLaPurge();

    expect($tache->expression)->toMatch('/^\d+ \d+ \* \* \*$/');

    // Le moniteur ne relit que les tâches qui ne portent pas `doNotMonitor()`.
    expect(Artisan::call('schedule-monitor:sync'))->toBe(0)
        ->and(TachePlanifiee::query()->where('name', 'like', 'queue:prune-failed%')->exists())->toBeTrue();
});

it('garde une tâche en échec trente jours, pas davantage, comme le promet la page de profil', function (): void {
    $purge = Carbon::parse('2026-10-12 01:30:00');

    tachesEnEchecConsigner('trente-et-un-jours', $purge->copy()->subDays(31));
    tachesEnEchecConsigner('vingt-neuf-jours', $purge->copy()->subDays(29));
    tachesEnEchecConsigner('la-veille', $purge->copy()->subDay());

    expect(tachesEnEchecRestantes())->toBe(['trente-et-un-jours', 'vingt-neuf-jours', 'la-veille']);

    Carbon::setTestNow($purge);

    // La ligne que le planificateur exécute, sans le binaire de PHP ni `artisan`.
    $commande = Str::after((string) tachesEnEchecLaPurge()->command, "'artisan' ");

    app()->detectEnvironment(static fn (): string => 'production');
    $codeDeSortie = Artisan::call($commande);

    expect($codeDeSortie)->toBe(0, Artisan::output())
        ->and(tachesEnEchecRestantes())->toBe(['vingt-neuf-jours', 'la-veille'])
        ->and(preg_replace('/\s+/', ' ', (string) file_get_contents(resource_path('js/Pages/Profile/Partials/DeleteUserForm.vue'))))->toContain("trente jours dans les rapports d'erreurs");
});
