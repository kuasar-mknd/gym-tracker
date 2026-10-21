<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * En ligne, une session finie mène à la connexion depuis « Séance »,
 * « Lancer » et « Terminer » (#2049).
 *
 * Avant #2049, les trois gestes étaient des visites d'Inertia, que le serveur
 * renvoyait à la connexion. Ils passent par l'API, qui répond 401 (la session
 * fermée par un mot de passe changé ailleurs) ou 419 (la session expirée, son
 * jeton CSRF avec elle) : la page disait « Réessaie » à chaque appui, sans
 * chemin vers la connexion. Elle se redemande désormais au serveur, qui mène
 * à la connexion. « Terminer » garde en plus la fin en file, à l'heure du
 * geste : reconnecté, la séance se clôt à cette heure.
 *
 * La session expire par l'effacement de ses lignes en base, comme à la fin de
 * sa durée de vie ; elle se ferme ailleurs par un mot de passe changé
 * (`forceFill`), comme `FinDeSessionCoteServeurTest`.
 */
class SessionExpireeSurLaSeanceTest extends DuskTestCase
{
    /**
     * Le chemin de la page, une fois arrivée à la connexion, ou ce que la page
     * dit à la place : de quoi comprendre un échec.
     */
    private const string CE_QUE_DIT_LA_PAGE = <<<'SCRIPT'
        return location.pathname + ' : ' + Array.from(document.querySelectorAll('[role=alert],[role=status]'))
            .map((element) => element.textContent.trim())
            .filter(Boolean)
            .join(' | ');
    SCRIPT;

    public function test_seance_la_session_expiree_mene_a_la_connexion_sans_rien_creer(): void
    {
        $compte = $this->unCompte('session-seance');

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)
                ->visit('/dashboard')
                ->waitFor('#main-content', 30)
                ->disableAnimations()
                ->waitFor('button.glass-nav-fab', 15)
                ->assertAttribute('button.glass-nav-fab', 'aria-label', 'Séance');

            $this->expirerLaSession($compte);
            $browser->script("document.querySelector('button.glass-nav-fab').click();");

            $this->attendreLaConnexion($browser, '« Séance »');
        });

        $this->assertSame(0, Workout::query()->where('user_id', $compte->id)->count(), 'une séance a été créée');
    }

    public function test_lancer_un_modele_la_session_expiree_mene_a_la_connexion_sans_rien_creer(): void
    {
        $compte = $this->unCompte('session-modele');
        $modele = WorkoutTemplate::factory()->create(['user_id' => $compte->id, 'name' => 'Modele '.Str::random(8)]);

        $this->browse(function (Browser $browser) use ($compte, $modele): void {
            $browser->loginAs($compte)
                ->visit('/templates')
                ->waitFor('#main-content', 30)
                ->disableAnimations()
                ->waitFor('[dusk="execute-template-'.$modele->id.'"]', 15);

            $this->expirerLaSession($compte);
            $browser->script("document.querySelector('[dusk=\"execute-template-{$modele->id}\"]').click();");

            $this->attendreLaConnexion($browser, '« Lancer »');
        });

        $this->assertSame(0, Workout::query()->where('user_id', $compte->id)->count(), 'une séance a été créée');
    }

    public function test_terminer_la_session_fermee_mene_a_la_connexion_puis_clot_la_seance_a_l_heure_du_geste(): void
    {
        $compte = $this->unCompte('session-fin');
        $seance = Workout::factory()->for($compte)->create([
            'name' => 'Seance '.Str::random(8),
            'started_at' => now()->subMinutes(40),
            'ended_at' => null,
        ]);
        $exercice = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Exercice '.Str::random(8), 'type' => 'strength']);
        WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id, 'order' => 0]);

        $this->browse(function (Browser $browser) use ($compte, $seance): void {
            $browser->loginAs($compte)
                ->visit('/workouts/'.$seance->id)
                ->waitFor('#main-content', 30)
                ->disableAnimations()
                ->waitFor('[dusk="finish-workout-mobile"]', 15);

            // Le mot de passe change ailleurs : la session de l'appareil est fermée.
            $compte->forceFill(['password' => Hash::make(Str::random(24))])->save();

            $browser->clickWhenSettled('[dusk="finish-workout-mobile"]')
                ->waitFor('dialog[open] [dusk="confirm-finish-button"]', 15);
            $avant = $this->maintenantDansLeNavigateur($browser);
            $browser->script("document.querySelector('dialog[open] [dusk=\"confirm-finish-button\"]').click();");
            $apres = $this->maintenantDansLeNavigateur($browser);

            $this->attendreLaConnexion($browser, '« Terminer »');
            $this->assertNull($seance->refresh()->ended_at, 'la séance a été close sans session');

            // La fin attend la file, à l'heure du geste.
            $fin = $browser->script(<<<'SCRIPT'
                return JSON.parse(localStorage.getItem('offline_sync_queue') || '[]')
                    .find((entree) => new URL(entree.url, location.href).pathname === '/api/v1/workouts/finish')?.data?.ended_at ?? null;
            SCRIPT)[0];
            $this->assertIsString($fin, 'la fin n’attend pas la file');
            $instant = (int) round(Date::parse($fin)->getPreciseTimestamp(3));
            $this->assertGreaterThanOrEqual($avant, $instant, 'la fin a été prise avant le geste');
            $this->assertLessThanOrEqual($apres, $instant, 'la fin a été prise après le geste');

            // Reconnectée, la personne revient à la séance : la fin part, à l'heure du geste.
            $browser->loginAs($compte)->visit('/workouts/'.$seance->id);

            $this->waitForDatabase(
                fn (): bool => $seance->refresh()->ended_at !== null,
                60,
                'la fin gardée en file n’est pas partie une fois reconnecté',
            );
            $this->assertSame(Date::parse($fin)->getTimestamp(), $seance->ended_at?->getTimestamp(), 'la séance n’est pas close à l’heure du geste');
            $browser->waitUntil("JSON.parse(localStorage.getItem('offline_sync_queue') || '[]').length === 0", 15, 'la file garde encore la fin');
        });
    }

    /**
     * Un compte vérifié, à une adresse tirée au hasard.
     */
    private function unCompte(string $prefixe): User
    {
        return User::factory()->create([
            'email' => $prefixe.'-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * La session du compte expire : ses lignes quittent la base, comme à la
     * fin de sa durée de vie.
     */
    private function expirerLaSession(User $compte): void
    {
        DB::table('sessions')->where('user_id', $compte->id)->delete();
    }

    /**
     * La page arrive à la connexion, où le serveur mène une session finie.
     */
    private function attendreLaConnexion(Browser $browser, string $geste): void
    {
        $browser->waitUntil(
            "location.pathname === '/login'",
            20,
            "la session finie, {$geste} ne mène pas à la connexion : ".(string) ($browser->script(self::CE_QUE_DIT_LA_PAGE)[0] ?? ''),
        );
    }

    /**
     * L'heure du navigateur, en millisecondes : celle qu'il donne au geste.
     */
    private function maintenantDansLeNavigateur(Browser $browser): int
    {
        $maintenant = $browser->script('return Date.now();')[0];
        $this->assertIsInt($maintenant);

        return $maintenant;
    }
}
