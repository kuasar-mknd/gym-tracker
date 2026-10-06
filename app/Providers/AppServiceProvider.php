<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Admin;
use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Services\StreakService;
use App\Support\ConnexionSociale\FournisseurApple;
use App\Support\LiensDesCourriels;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
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
        $this->batirLesLiensDesCourrielsSurAppUrl();
        $this->refuserLesArchivesEnClair();
        $this->ouvrirLesOutilsAuSuperAdministrateur();
        \BezhanSalleh\FilamentExceptions\Facades\FilamentExceptions::model(\App\Models\ExceptionEnregistree::class);
        $this->ouvrirLeLecteurDeJournaux();

        // Le nonce CSP n'est plus tiré ici mais à chaque requête, par
        // NonceCspParRequete : sous Octane, boot() ne tourne qu'une fois par
        // worker, et son nonce servait à tous les utilisateurs (#1904).

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

        $this->registerWebPushFailureLog();
    }

    /**
     * Les liens de réinitialisation et de vérification d'adresse se bâtissent
     * sur `APP_URL`, jamais sur l'hôte de la requête qui déclenche l'envoi.
     * `LiensDesCourriels` dit pourquoi, et comment la signature du lien de
     * vérification reste valide.
     */
    private function batirLesLiensDesCourrielsSurAppUrl(): void
    {
        ResetPassword::createUrlUsing(LiensDesCourriels::lienDeReinitialisation(...));
        VerifyEmail::createUrlUsing(LiensDesCourriels::lienDeVerification(...));
    }

    /**
     * Un push refusé laissait la tâche en succès et ne s'écrivait nulle part.
     *
     * Le canal demande à Apple ou à Google de délivrer, lit leur rapport, et
     * s'arrête là : `NotificationFailed` est bien émis, personne ne l'écoutait.
     * Un appareil pouvait donc cesser de recevoir pendant des semaines sans
     * qu'aucun journal, aucune trace et aucune alerte ne le disent — la panne
     * n'était pas difficile à diagnostiquer, elle était invisible.
     *
     * Le journal seulement, jamais la base : chaque écriture SQL coûte de
     * 350 ms à 1,7 s en production, et un abonnement mort en produit une par envoi.
     * Le point de terminaison est réduit à son hôte, parce que l'URL entière est
     * une capacité : qui la détient peut écrire à l'appareil.
     */
    private function registerWebPushFailureLog(): void
    {
        \Illuminate\Support\Facades\Event::listen(function (\NotificationChannels\WebPush\Events\NotificationFailed $event): void {
            $rapport = $event->report;

            \Illuminate\Support\Facades\Log::warning('Envoi push refusé.', [
                'titre' => $event->message->toArray()['title'] ?? null,
                'hote' => parse_url($rapport->getEndpoint(), PHP_URL_HOST),
                'expire' => $rapport->isSubscriptionExpired(),
                'statut' => $rapport->getResponse()?->getStatusCode(),
                'raison' => $rapport->getReason(),
            ]);
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
            $idUtilisateur = $set->workoutLine?->workout?->user_id;

            if ($idUtilisateur !== null) {
                app(\App\Services\RecommendedValuesService::class)->invaliderPour((int) $idUtilisateur);
            }
        });

        // `personal_records.set_id` est ON DELETE SET NULL : apres coup, plus rien
        // ne dit ce que la serie detenait.
        Set::deleting(function (Set $set): void {
            app(\App\Services\PersonalRecordService::class)->retenirTypesDetenus($set);
        });

        /*
         * Sans garde sur une liste vide : `refreshFor()` regarde aussi, sous le
         * verrou de l'exercice, les records qu'une synchronisation a ecrits
         * pour la serie apres la lecture de `deleting` (#1984).
         */
        Set::deleted(function (Set $set): void {
            $records = app(\App\Services\PersonalRecordService::class);

            $records->refreshFor($set, null, $records->typesRetenus($set));
        });

        /*
         * Après la reconstruction des records ci-dessus, que lit l'objectif de
         * charge : les écouteurs d'un même événement tournent dans l'ordre où
         * ils sont posés. Sans ce recalcul, supprimer la série qui avait
         * atteint un objectif le laissait atteint (#1953).
         */
        Set::deleted(function (Set $set): void {
            $user = $set->workoutLine?->workout?->user;

            if ($user instanceof User) {
                \App\Jobs\SyncUserGoals::dispatch($user);
            }
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

                // Après les records, que lit l'objectif de charge (#1953).
                \App\Jobs\SyncUserGoals::dispatch($user);
            }
        });
    }

    private function registerWorkoutEvents(): void
    {
        Workout::saved(function (Workout $workout): void {
            /*
             * La série ne bouge qu'à la création de la séance ou au changement
             * de sa date : renommer une séance ne change rien au calendrier.
             *
             * Une séance déplacée quitte un jour autant qu'elle en gagne un :
             * l'avance d'un cran ajoutait le jour d'arrivée sans retirer celui
             * de départ, et chaque déplacement vers l'avant allongeait la série
             * et son record (#1983). Elle se reconstruit donc depuis les
             * séances, comme après une suppression. La création seule garde
             * l'avance d'un cran.
             */
            if ($workout->wasChanged('started_at')) {
                app(StreakService::class)->recalculerDepuisLesFaits($workout->user);
            } elseif ($workout->wasRecentlyCreated) {
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

            // Les objectifs se recalculent dans `Workout::booted()`, après les
            // records qu'ils lisent : cet écouteur-ci tourne avant (#1953).
        });
    }

    /**
     * Une pesée ou une mensuration de partie du corps relance le recalcul des
     * objectifs qui les suivent.
     *
     * Les parties du corps (tour de taille, poitrine…) se lisent dans
     * `body_part_measurements` depuis #1454 ; sans leurs écouteurs, saisir ou
     * supprimer une mesure ne faisait bouger l'objectif qu'à la pesée ou à la
     * série suivante (#1954).
     */
    private function registerMeasurementEvents(): void
    {
        BodyMeasurement::saved(fn (BodyMeasurement $bm) => \App\Jobs\SyncUserGoals::dispatch($bm->user));
        BodyMeasurement::deleted(fn (BodyMeasurement $bm) => \App\Jobs\SyncUserGoals::dispatch($bm->user));
        BodyPartMeasurement::saved(fn (BodyPartMeasurement $mesure) => \App\Jobs\SyncUserGoals::dispatch($mesure->user));
        BodyPartMeasurement::deleted(fn (BodyPartMeasurement $mesure) => \App\Jobs\SyncUserGoals::dispatch($mesure->user));
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
     *
     * Le pilote annoncé est `FournisseurApple`, celui du paquet réglé sans
     * session et avec le nonce par cookie : le rappel d'Apple arrive en POST
     * inter-sites, sans cookie de session (#1911).
     *
     * Ce cookie arrive déjà chiffré par le paquet, avec `APP_KEY` : chiffré une
     * seconde fois par `EncryptCookies`, il pesait 827 octets, et la
     * redirection vers Apple passait le budget d'en-têtes du proxy inverse
     * (EnTetesDeReponseTest). Un cookie forgé ou modifié ne se déchiffre pas,
     * et le retour est refusé. L'exclusion est posée ici, à côté du pilote
     * qu'elle sert, et non dans bootstrap/app.php ; `except()` est statique et
     * survit d'une requête à l'autre sous Octane.
     */
    private function registerAppleSocialiteDriver(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('apple', FournisseurApple::class);
        });

        EncryptCookies::except(FournisseurApple::COOKIE_DU_NONCE);
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
     * Le lecteur de journaux vit sous /backoffice mais hors du panneau : sa porte
     * est `view-logs`, celle qui montre son lien dans le menu.
     *
     * Une porte du Gate, et non le rappel de `LogViewer::auth()` : le paquet lie
     * son service en `scoped`, qu'Octane oublie après chaque requête. Le rappel
     * posé au démarrage ne servait que la première requête d'un worker ; ensuite
     * `AuthorizeLogViewer` ne trouvait ni rappel ni porte, refusait tout le monde
     * en production (403, page et API), et laissait passer tout administrateur
     * du panneau ailleurs. Les définitions du Gate vivent dans l'application de
     * base, d'où chaque requête est clonée.
     *
     * Le paquet l'évalue par `Gate::authorize()`, donc pour l'utilisateur de la
     * garde par défaut, que `Filament\Http\Middleware\Authenticate` vient de
     * régler sur celle du panneau (`config/log-viewer.php`) : seul un
     * administrateur passe, même si un compte de l'application est connecté à
     * côté.
     */
    private function ouvrirLeLecteurDeJournaux(): void
    {
        Gate::define('viewLogViewer', fn (?Authenticatable $utilisateur = null): bool => $utilisateur instanceof Admin
            && $utilisateur->can('view-logs'));
    }

    /**
     * Les greffons demandent `create-backup`, `download-backup`, `delete-backup`
     * et `view-health` ; Shield ne les connaît pas et ne pose aucune porte pour
     * le super administrateur, si bien que personne ne voyait le bouton. Les
     * quatre capacités des exceptions suivent le même chemin : la ressource
     * s'ouvre au super administrateur sans passer par `shield:generate` en
     * production, et une permission Shield accordée à un autre rôle marche aussi.
     *
     * `viewPulse` aussi : Pulse pose sa propre porte, ouverte au seul
     * environnement `local`, si bien que `/backoffice/pulse` répondait 403 en
     * production et que son lien restait caché. La nôtre est définie après la
     * sienne (Pulse la pose dès que le Gate est résolu, donc avant notre premier
     * `Gate::define`) et la remplace, en local compris.
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
            'viewPulse',
        ];

        foreach ($capacites as $capacite) {
            Gate::define($capacite, fn (?Authenticatable $utilisateur = null): bool => $utilisateur instanceof Admin
                && $utilisateur->hasRole($superAdministrateur));
        }
    }
}
