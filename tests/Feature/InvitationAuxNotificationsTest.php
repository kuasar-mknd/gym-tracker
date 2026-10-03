<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\Workout;
use App\Notifications\PersonalRecordAchieved;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;
use NotificationChannels\WebPush\WebPushChannel;

/*
 * L'invitation aux notifications (#1848).
 *
 * Rien dans l'application ne proposait d'activer les notifications : le seul
 * chemin était un bandeau du profil, à trois navigations de l'accueil. La fin
 * d'une séance propose désormais l'activation, quand un record vient d'avoir
 * un sens. Deux moitiés côté serveur :
 *
 * - la fin de séance pose une donnée flash, seulement pour un compte qui n'a
 *   encore aucun abonnement et n'a pas coupé les notifications de records ;
 * - l'activation allume l'envoi push des records, et lui seul. S'abonner ne
 *   suffit pas : `PersonalRecordAchieved` n'envoie en push que si la
 *   préférence le dit, et un compte neuf n'a aucune ligne de préférence. Une
 *   activation qui ne ferait que s'abonner semblerait réussir et n'enverrait
 *   rien.
 */

function seanceEnCoursPourLInvitation(User $utilisateur): Workout
{
    return Workout::factory()->create([
        'user_id' => $utilisateur->id,
        'started_at' => now()->subHour(),
        'ended_at' => null,
    ]);
}

function preferenceDeRecordsDe(User $utilisateur): ?NotificationPreference
{
    return NotificationPreference::query()
        ->where('user_id', $utilisateur->id)
        ->where('type', 'personal_record')
        ->first();
}

/**
 * Remplace la protection CSRF par une sous-classe qui ne s'efface pas sous les
 * tests : sans elle, aucun test HTTP ne voit jamais un 419.
 */
function protegerLInvitationCommeEnProduction(Application $application): void
{
    $application->bind(
        PreventRequestForgery::class,
        fn (Application $app): PreventRequestForgery => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        },
    );
}

describe('la fin de séance', function (): void {
    it('propose les notifications au compte qui n’a encore aucun abonnement', function (): void {
        $utilisateur = User::factory()->create();

        $this->actingAs($utilisateur)
            ->patch(route('workouts.update', seanceEnCoursPourLInvitation($utilisateur)), ['is_finished' => true])
            ->assertRedirect(route('dashboard'))
            ->assertInertiaFlash('proposerLesNotifications', true);

        // L'accueil où la redirection mène la reçoit, et elle seule.
        $this->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Dashboard')
                ->hasFlash('proposerLesNotifications', true));

        $this->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->missingFlash('proposerLesNotifications'));
    });

    it('ne propose rien pour une autre modification de la séance', function (): void {
        $utilisateur = User::factory()->create();

        $this->actingAs($utilisateur)
            ->patch(route('workouts.update', seanceEnCoursPourLInvitation($utilisateur)), ['name' => 'Jambes'])
            ->assertRedirect()
            ->assertInertiaFlashMissing('proposerLesNotifications');
    });

    it('ne propose rien au compte dont le serveur tient déjà un abonnement', function (): void {
        $utilisateur = User::factory()->create();
        $utilisateur->updatePushSubscription('https://push.example.com/telephone', 'cle', 'jeton');

        $this->actingAs($utilisateur)
            ->patch(route('workouts.update', seanceEnCoursPourLInvitation($utilisateur)), ['is_finished' => true])
            ->assertRedirect(route('dashboard'))
            ->assertInertiaFlashMissing('proposerLesNotifications');
    });

    it('ne propose rien au compte qui a coupé les notifications de records', function (): void {
        $utilisateur = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $utilisateur->id,
            'type' => 'personal_record',
            'is_enabled' => false,
            'is_push_enabled' => false,
        ]);

        $this->actingAs($utilisateur)
            ->patch(route('workouts.update', seanceEnCoursPourLInvitation($utilisateur)), ['is_finished' => true])
            ->assertRedirect(route('dashboard'))
            ->assertInertiaFlashMissing('proposerLesNotifications');
    });

    it('ne compte pas l’abonnement d’un autre compte', function (): void {
        User::factory()->create()->updatePushSubscription('https://push.example.com/autre', 'cle', 'jeton');
        $utilisateur = User::factory()->create();

        $this->actingAs($utilisateur)
            ->patch(route('workouts.update', seanceEnCoursPourLInvitation($utilisateur)), ['is_finished' => true])
            ->assertInertiaFlash('proposerLesNotifications', true);
    });
});

describe('l’activation des envois push des records', function (): void {
    it('allume le seul envoi push des records, sans toucher aux autres préférences', function (): void {
        $utilisateur = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $utilisateur->id,
            'type' => 'training_reminder',
            'is_enabled' => true,
            'is_push_enabled' => false,
            'value' => null,
            'days' => [1, 3],
        ]);

        $this->actingAs($utilisateur)
            ->patchJson(route('profile.push-preferences.update'), ['types' => ['personal_record']])
            ->assertNoContent();

        $records = preferenceDeRecordsDe($utilisateur);
        expect($records)->not->toBeNull()
            ->and($records?->is_enabled)->toBeTrue()
            ->and($records?->is_push_enabled)->toBeTrue()
            ->and($records?->days)->toBeNull();

        // Les rappels restent à activer dans le profil.
        $rappels = NotificationPreference::query()->where('user_id', $utilisateur->id)->where('type', 'training_reminder')->sole();
        expect($rappels->is_enabled)->toBeTrue()
            ->and($rappels->is_push_enabled)->toBeFalse()
            ->and($rappels->days)->toBe([1, 3]);

        expect(NotificationPreference::query()->where('user_id', $utilisateur->id)->count())->toBe(2);
    });

    it('ne réécrit que l’envoi push d’une préférence de records existante', function (bool $activee): void {
        $utilisateur = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $utilisateur->id,
            'type' => 'personal_record',
            'is_enabled' => $activee,
            'is_push_enabled' => false,
            'value' => 7,
            'days' => [2],
        ]);

        $this->actingAs($utilisateur)
            ->patchJson(route('profile.push-preferences.update'), ['types' => ['personal_record']])
            ->assertNoContent();

        $records = preferenceDeRecordsDe($utilisateur);
        expect($records?->is_push_enabled)->toBeTrue()
            ->and($records?->is_enabled)->toBe($activee)
            ->and($records?->value)->toBe(7)
            ->and($records?->days)->toBe([2]);
    })->with([
        'records activés' => [true],
        'records coupés dans le profil' => [false],
    ]);

    /**
     * La preuve que l'activation enverra : la notification d'un record choisit
     * désormais le canal push, ce qu'un simple abonnement ne lui faisait pas
     * faire.
     */
    it('rend le prochain record réellement envoyable en push', function (): void {
        $utilisateur = User::factory()->create();
        $record = PersonalRecord::factory()->create(['user_id' => $utilisateur->id]);

        expect(new PersonalRecordAchieved($record)->via($utilisateur->refresh()))->not->toContain(WebPushChannel::class);

        $this->actingAs($utilisateur)
            ->patchJson(route('profile.push-preferences.update'), ['types' => ['personal_record']])
            ->assertNoContent();

        $apres = $utilisateur->refresh();
        expect($apres->notificationActivee('personal_record'))->toBeTrue()
            ->and($apres->notificationsPoussesActivees('personal_record'))->toBeTrue()
            ->and($apres->notificationsPoussesActivees('training_reminder'))->toBeFalse()
            ->and(new PersonalRecordAchieved($record)->via($apres))->toContain(WebPushChannel::class);
    });

    it('refuse tout autre type que les records, sans rien écrire', function (array $corps, string $champ): void {
        $utilisateur = User::factory()->create();

        $this->actingAs($utilisateur)
            ->patchJson(route('profile.push-preferences.update'), $corps)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($champ);

        expect(NotificationPreference::query()->count())->toBe(0);
    })->with([
        'les rappels' => [['types' => ['training_reminder']], 'types.0'],
        'un type inconnu' => [['types' => ['personal_record', 'inconnu']], 'types.1'],
        'deux fois les records' => [['types' => ['personal_record', 'personal_record']], 'types.0'],
        'aucun type' => [['types' => []], 'types'],
        'rien' => [[], 'types'],
    ]);

    it('refuse un visiteur', function (): void {
        $this->patchJson(route('profile.push-preferences.update'), ['types' => ['personal_record']])
            ->assertUnauthorized();

        $this->patch(route('profile.push-preferences.update'), ['types' => ['personal_record']])
            ->assertRedirect(route('login'));

        expect(NotificationPreference::query()->count())->toBe(0);
    });

    it('n’écrit que pour le compte connecté, quoi que dise la requête', function (): void {
        $connecte = User::factory()->create();
        $autre = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $autre->id,
            'type' => 'personal_record',
            'is_enabled' => true,
            'is_push_enabled' => false,
        ]);

        $this->actingAs($connecte)
            ->patchJson(route('profile.push-preferences.update'), [
                'types' => ['personal_record'],
                'user_id' => $autre->id,
            ])
            ->assertNoContent();

        expect(preferenceDeRecordsDe($autre)?->is_push_enabled)->toBeFalse()
            ->and(preferenceDeRecordsDe($connecte)?->is_push_enabled)->toBeTrue();
    });

    it('limite le débit comme les autres écritures du profil', function (): void {
        $route = app('router')->getRoutes()->getByName('profile.push-preferences.update');
        $voisine = app('router')->getRoutes()->getByName('profile.preferences.update');

        $limites = fn (?Illuminate\Routing\Route $r): array => array_values(array_filter(
            $r?->gatherMiddleware() ?? [],
            fn (mixed $m): bool => is_string($m) && str_starts_with($m, 'throttle:'),
        ));

        expect($limites($route))->not->toBe([])
            ->and($limites($route))->toBe($limites($voisine));

        $plafond = (int) preg_replace('/^throttle:(\d+),.*$/', '$1', (string) $limites($route)[0]);
        expect($plafond)->toBeGreaterThan(0);
        $utilisateur = User::factory()->create();

        // La limite se compte par compte : on l'amène à son plafond.
        RateLimiter::increment(sha1((string) $utilisateur->id), 60, $plafond);

        $this->actingAs($utilisateur)
            ->patchJson(route('profile.push-preferences.update'), ['types' => ['personal_record']])
            ->assertTooManyRequests();

        expect(preferenceDeRecordsDe($utilisateur))->toBeNull();
    });

    it('refuse une écriture sans jeton CSRF venue d’ailleurs', function (): void {
        protegerLInvitationCommeEnProduction(app());
        $utilisateur = User::factory()->create();

        $this->actingAs($utilisateur)
            ->withHeader('Sec-Fetch-Site', 'cross-site')
            ->patchJson(route('profile.push-preferences.update', absolute: false), ['types' => ['personal_record']])
            ->assertStatus(419);

        expect(preferenceDeRecordsDe($utilisateur))->toBeNull();

        // Le pendant : avec le jeton de la session, la même écriture passe.
        $this->actingAs($utilisateur)
            ->withSession(['_token' => 'jeton-de-la-session'])
            ->withHeader('X-CSRF-TOKEN', 'jeton-de-la-session')
            ->patchJson(route('profile.push-preferences.update', absolute: false), ['types' => ['personal_record']])
            ->assertNoContent();

        expect(preferenceDeRecordsDe($utilisateur)?->is_push_enabled)->toBeTrue();
    });
});
