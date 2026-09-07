<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Admin;
use App\Models\BodyMeasurement;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Services\StreakService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use SocialiteProviders\Apple\Provider as AppleProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use Spatie\Backup\Events\BackupManifestWasCreated;

final class AppServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(\App\Services\GoalService::class);
        $this->app->singleton(\App\Services\AchievementService::class);
        $this->app->singleton(\App\Services\NotificationService::class);
        $this->app->singleton(\App\Services\PersonalRecordService::class);

        if (config('app.env') === 'testing') {
            config(['telescope.enabled' => false]);
        }

        if (config('app.env') === 'local' && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(\App\Providers\TelescopeServiceProvider::class);
        }
    }

    public function boot(): void
    {
        $this->registerAppleSocialiteDriver();
        $this->refuserLesArchivesEnClair();
        $this->ouvrirLesOutilsAuSuperAdministrateur();
        \BezhanSalleh\FilamentExceptions\Facades\FilamentExceptions::model(\App\Models\ExceptionEnregistree::class);
        // Le lecteur de journaux vit sous /backoffice mais hors du panneau : sa
        // porte est la même, dite au paquet.
        \Opcodes\LogViewer\Facades\LogViewer::auth(fn (\Illuminate\Http\Request $request): bool => $request->user('admin')?->can('view-logs') ?? false);

        if (config('app.env') === 'testing') {
            Gate::define('viewPulse', fn ($user = null): bool => true);
        }

        Vite::useCspNonce();

        // L'absence de Vite::prefetch est voulue.
        //
        // Il injectait un <link rel="prefetch"> pour chaque fichier du manifeste,
        // si bien que chaque page tirait tout le build en arrière-plan. Mesuré
        // sur /workouts : 141 requêtes pour 1350 Ko, dont 120 préchargements dont
        // la page n'avait pas besoin, contre les 21 qu'elle utilisait.
        //
        // Le service worker garde déjà la coquille de ce build — onze entrées
        // depuis #1814 — donc le préchargement rapportait une seconde copie de ce
        // que l'application s'était engagée à garder, et le reste par-dessus. Sur
        // un téléphone en données mobiles, c'est le paquet entier à chaque page.
        //
        // Ce qu'il achetait, c'était une première navigation instantanée vers
        // chaque page. Dans une application Inertia, une navigation ne demande
        // que le morceau de cette page, quelques dizaines de kilo-octets : on
        // dépensait donc des méga-octets pour économiser une petite requête — et
        // le service worker couvre les visites suivantes de toute manière.

        Model::shouldBeStrict(config('app.env') !== 'production');

        Password::defaults(function () {
            $rule = Password::min(8);

            return config('app.env') === 'production'
                ? $rule->mixedCase()->uncompromised()
                : $rule;
        });

        $this->registerSetEvents();
        $this->registerWorkoutEvents();
        $this->registerMeasurementEvents();

        \Illuminate\Support\Facades\Event::listen(function (\Illuminate\Notifications\Events\NotificationSent $event): void {
            if ($event->notifiable instanceof \App\Models\User) {
                app(\App\Services\NotificationService::class)->clearCache($event->notifiable, $event->notification::class);
            }
        });
    }

    private function registerSetEvents(): void
    {
        Set::saved(function (Set $set): void {
            $user = $set->workoutLine->workout->user;

            /*
             * Le test disait « renseigne » et signifiait « ni nul ni zero ».
             * Les deux cas doivent bien etre ecartes — une serie a zero kilo ou
             * zero repetition ne produit que des records nuls — mais il faut
             * l'ecrire, d'autant que `PersonalRecordService::shouldSkipSync()`
             * refait le meme controle a l'arrivee : cette garde-ci n'evite que
             * la mise en file d'un travail sans objet.
             */
            if ($set->weight !== null && $set->weight > 0.0 && $set->reps !== null && $set->reps > 0) {
                if (config('app.env') === 'testing' || config('database.connections.mysql.database') === 'gym_tracker_testing') {
                    \App\Jobs\SyncPersonalRecord::dispatchSync($set, $user);
                } else {
                    \App\Jobs\SyncPersonalRecord::dispatch($set, $user)->afterCommit();
                }
            }

            /**
             * Un record ne faisait que monter. Corriger un poids mal saisi
             * laissait le chiffre gonflé sur le profil pour de bon, parce que
             * rien ne recalculait le record que la série corrigée détenait.
             */
            app(\App\Services\PersonalRecordService::class)->refreshRecordsHeldBy($set, $user);
            app(\App\Services\RecommendedValuesService::class)->invaliderPour((int) $user->id);

            \App\Jobs\SyncUserAchievements::dispatch($user);
            \App\Jobs\SyncUserGoals::dispatch($user);
        });

        Set::deleted(function (Set $set): void {
            $userId = $set->workoutLine?->workout?->user_id;

            if ($userId !== null) {
                app(\App\Services\RecommendedValuesService::class)->invaliderPour((int) $userId);
            }
        });

        // `personal_records.set_id` est ON DELETE SET NULL : apres coup, plus rien
        // ne dit ce que la serie detenait.
        Set::deleting(function (Set $set): void {
            app(\App\Services\PersonalRecordService::class)->retenirTypesDetenus($set);
        });

        Set::deleted(function (Set $set): void {
            $records = app(\App\Services\PersonalRecordService::class);
            $detenus = $records->typesRetenus($set);

            if ($detenus === []) {
                return;
            }

            $records->refreshFor($set, null, $detenus);
        });

        \App\Models\WorkoutLine::deleted(function (\App\Models\WorkoutLine $line): void {
            /**
             * Retirer un exercice emporte ses séries par un ON DELETE CASCADE,
             * qui ne déclenche aucun événement de modèle — sans ceci, un record
             * établi pendant une séance ensuite supprimée tenait indéfiniment,
             * en pointant vers une ligne qui n'existe plus.
             */
            $user = $line->workout?->user;

            /*
             * Le `?->` ci-dessus n'est pas decoratif : pendant la suppression en
             * cascade d'une seance, la ligne parente peut avoir disparu — c'est
             * le defaut corrige en #1476. Le docblock du modele declare pourtant
             * `workout` non nul, ce qui faisait de ce test une entree de
             * baseline : c'est le docblock qui est optimiste, pas la garde.
             */
            if ($user instanceof User) {
                app(\App\Services\PersonalRecordService::class)->recompute($user, $line->exercise_id);
            }
        });
    }

    private function registerWorkoutEvents(): void
    {
        Workout::saved(function (Workout $workout): void {
            // La série ne bouge qu'à la création de la séance ou au changement
            // de sa date : renommer une séance ne change rien au calendrier.
            if ($workout->wasRecentlyCreated || $workout->wasChanged('started_at')) {
                app(StreakService::class)->updateStreak($workout->user, $workout);
            }

            \App\Jobs\SyncUserAchievements::dispatch($workout->user);
            \App\Jobs\SyncUserGoals::dispatch($workout->user);
        });

        /**
         * Supprimer une seance recalcule la serie depuis ce qui reste.
         *
         * Rien ne le faisait : `Workout::deleting` relachait le volume,
         * `Workout::deleted` reconstruisait les records, et la serie restait
         * telle quelle. `last_workout_at` continuait de pointer une seance
         * disparue — or c'est la seule memoire du service, donc l'ecart
         * calcule a la seance SUIVANTE partait d'une date fantome et cassait
         * une serie pourtant continue. `longest_streak` n'etait jamais revu a
         * la baisse non plus. C'est #1460.
         *
         * Reconstruction complete et non ajustement : c'est la seule facon
         * d'etre juste apres une suppression, qui peut retirer un jour au
         * milieu d'une suite aussi bien qu'a son extremite.
         */
        Workout::deleted(function (Workout $workout): void {
            $user = $workout->user;

            if ($user === null) {
                return;
            }

            app(StreakService::class)->recalculerDepuisLesFaits($user);
        });
    }

    private function registerMeasurementEvents(): void
    {
        BodyMeasurement::saved(fn (BodyMeasurement $bm) => \App\Jobs\SyncUserGoals::dispatch($bm->user));
        BodyMeasurement::deleted(fn (BodyMeasurement $bm) => \App\Jobs\SyncUserGoals::dispatch($bm->user));
    }

    /**
     * Apprend Apple à Socialite, qui ne le fournit pas.
     *
     * `socialiteproviders/apple` était dans composer.json et rien n'avait jamais
     * dit à Socialite qu'il était là : `Socialite::driver('apple')` levait
     * « Driver [apple] not supported » — une erreur 500 sur le troisième bouton
     * de la page de connexion, avec ou sans identifiants configurés. Les
     * fournisseurs communautaires s'annoncent par cet événement ; sans écouteur,
     * le paquet est inerte.
     */
    private function registerAppleSocialiteDriver(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('apple', AppleProvider::class);
        });
    }

    /**
     * Sans mot de passe d'archive, aucune sauvegarde n'est écrite, qu'elle vienne
     * du planificateur, de Filament ou d'un `backup:run` lancé à la main.
     */
    private function refuserLesArchivesEnClair(): void
    {
        Event::listen(BackupManifestWasCreated::class, function (): void {
            if (blank(config('backup.backup.password'))) {
                throw new RuntimeException('BACKUP_ARCHIVE_PASSWORD est vide : aucune archive en clair ne sera écrite.');
            }
        });
    }

    /**
     * Les greffons demandent `create-backup`, `download-backup`, `delete-backup`
     * et `view-health` ; Shield ne les connaît pas et ne pose aucune porte pour
     * le super administrateur, si bien que personne ne voyait le bouton. Les
     * quatre capacités des exceptions suivent le même chemin : la ressource
     * s'ouvre au super administrateur sans passer par `shield:generate` en
     * production, et une permission Shield accordée à un autre rôle marche aussi.
     */
    private function ouvrirLesOutilsAuSuperAdministrateur(): void
    {
        $role = config('filament-shield.super_admin.name');
        $superAdministrateur = is_string($role) ? $role : 'super_admin';

        $capacites = [
            'create-backup',
            'download-backup',
            'delete-backup',
            'view-health',
            'ViewAny:ExceptionEnregistree',
            'View:ExceptionEnregistree',
            'Delete:ExceptionEnregistree',
            'ViewAny:ErreurNavigateur',
            'View:ErreurNavigateur',
            'Delete:ErreurNavigateur',
            'ViewAny:TachePlanifiee',
            'View:TachePlanifiee',
            'view-logs',
            'view-outils',
        ];

        foreach ($capacites as $capacite) {
            Gate::define($capacite, fn (?Authenticatable $utilisateur = null): bool => $utilisateur instanceof Admin
                && $utilisateur->hasRole($superAdministrateur));
        }
    }
}
