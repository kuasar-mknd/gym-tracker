<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

/**
 * Un compte supprimé emporte ses séances avant lui (#1982), et ce qui le
 * désigne sans clé étrangère (#1935).
 *
 * Les tables liées au compte par `user_id` suivent par `ON DELETE CASCADE`,
 * à une exception près. La cascade vers `exercises` emporte les exercices
 * personnels, que `workout_lines.exercise_id` protège par une clé sans
 * `ON DELETE`, donc en RESTRICT : c'est voulu, `ExerciseController::destroy`
 * ne retire jamais un exercice utilisé. Les séances du compte, si elles sont
 * encore là quand vient le tour de ses exercices, font échouer toute la
 * suppression dès que l'une d'elles utilise un exercice personnel. Elles
 * partent donc d'abord, juste avant la ligne du compte et dans la même
 * transaction (`performDeleteOnModel()`) ; leurs lignes et leurs séries
 * suivent par cascade, et la clé garde son RESTRICT.
 *
 * Les tables que `TRACES_POLYMORPHES` énumère désignent le compte par une
 * relation polymorphe, un couple `*_type` / `*_id` : la base ne sait pas que
 * la ligne parle d'un compte, et rien ne la suivait. Le jeton de
 * réinitialisation du mot de passe le désigne par son adresse de courriel, et
 * restait de même (#1938).
 *
 * La table `sessions` n'y est pas, à dessein (#1938). Sa colonne `user_id`
 * reçoit l'identifiant de la garde PAR DÉFAUT au moment où la session
 * s'écrit, et le panneau fait de la garde `admin` celle de ses requêtes
 * (`Filament\Http\Middleware\Authenticate`) : la colonne mêle comptes et
 * administrateurs, sans type pour les distinguer, et un effacement par
 * `user_id` déconnecterait l'administrateur qui porte le même identifiant.
 * La production garde d'ailleurs ses sessions dans Redis, hors de la base ;
 * ailleurs, la ligne d'un compte supprimé ne retrouve plus personne, elle
 * redevient celle d'un invité, et le ramasse-miettes des sessions la retire
 * après `SESSION_LIFETIME` minutes d'inactivité.
 *
 * Les erreurs du navigateur (`erreurs_navigateur`) et les exceptions levées
 * par une requête du compte (`filament_exceptions_table`) ont une clé
 * étrangère et suivent par la cascade, comme le reste (#1938). Une exception
 * levée sans compte connecté n'a pas de lien, même quand elle recopie son
 * identifiant ou ses saisies : elle part avec la purge à trente jours.
 *
 * Quand c'est un administrateur qui supprime, une entrée d'audit anonyme le
 * dit, sans rien du compte (#1938).
 *
 * Réservé à `User`, qui porte aussi `LogsActivity`.
 */
trait EffaceSesTracesPolymorphes
{
    /**
     * La description de l'entrée d'audit qu'écrit la suppression d'un compte
     * par un administrateur.
     */
    public const string SUPPRESSION_PAR_UN_ADMINISTRATEUR = 'Compte supprimé par un administrateur';

    /**
     * Une table, et le nom de sa relation polymorphe : `notifiable` pour le
     * couple `notifiable_type` / `notifiable_id`.
     *
     * Notifications (records, rappels, succès), abonnements push (adresse et
     * clés de chaque appareil), jetons d'API, rôles et permissions attribués
     * au compte, et journal d'activité, où le compte est la cause OU le
     * sujet : l'effacement prime sur l'audit pour un compte qui demande sa
     * suppression.
     *
     * `LesTablesPolymorphesSuiventLeCompteTest` exige que toute colonne
     * `*_type` de la base figure ici ou dans ses exceptions justifiées, et
     * `SuppressionDuCompteTest` sème puis vérifie chaque entrée.
     */
    public const array TRACES_POLYMORPHES = [
        'notifications.notifiable',
        'push_subscriptions.subscribable',
        'personal_access_tokens.tokenable',
        'model_has_roles.model',
        'model_has_permissions.model',
        'activity_log.causer',
        'activity_log.subject',
    ];

    /**
     * Supprime le compte et, dans la même transaction, ses séances, son jeton
     * de réinitialisation et tout ce que `TRACES_POLYMORPHES` lui rattache.
     *
     * Ici plutôt que dans un écouteur `deleting` ou `deleted`, pour deux
     * raisons. Un écouteur tourne À L'INTÉRIEUR de `delete()` et ne peut pas
     * ouvrir de transaction autour : une panne au milieu de l'effacement
     * laisserait un compte disparu et des lignes orphelines. Et tous les
     * chemins passent par cette méthode : le profil, la page d'édition et la
     * suppression groupée du panneau (Filament supprime ligne à ligne),
     * `User::destroy()`, `forceDelete()`, et même `deleteQuietly()`, qui
     * coupe les évènements. Une action dédiée devrait être rappelée par
     * chacun, et c'est précisément cet oubli qui laissait ces lignes en base.
     * Seule une suppression par le constructeur de requêtes
     * (`User::query()->delete()`) y échappe ; l'application n'en fait aucune.
     *
     * Le journal d'activité est coupé pour cette instance le temps de la
     * suppression : l'entrée « deleted » recopierait nom et courriel dans
     * `activity_log` au moment où on l'efface, et, si le tampon du journal
     * était activé, elle n'y serait écrite qu'APRÈS l'effacement. Seule
     * l'entrée anonyme de `tracerLaSuppressionParUnAdministrateur()` s'écrit.
     */
    #[\Override]
    public function delete(): ?bool
    {
        $journalActif = $this->enableLoggingModelsEvents;
        $this->disableLogging();

        try {
            return $this->getConnection()->transaction(function (): ?bool {
                $supprime = parent::delete();

                if ($supprime === true) {
                    $this->effacerSonJetonDeReinitialisation();
                    $this->effacerSesTracesPolymorphes();
                    $this->tracerLaSuppressionParUnAdministrateur();
                }

                return $supprime;
            });
        } finally {
            $this->enableLoggingModelsEvents = $journalActif;
        }
    }

    /**
     * Efface les séances du compte, puis la ligne du compte (#1982).
     *
     * `delete()` appelle cette méthode dans sa transaction, après l'évènement
     * `deleting` : un écouteur qui refuse la suppression en rendant `false`
     * la refuse avant que la moindre séance ne parte. Effacer les séances en
     * tête de `delete()`, avant `parent::delete()`, les aurait perdues pour un
     * compte qui reste.
     *
     * Le constructeur de requêtes plutôt que `Workout::delete()` séance par
     * séance : les écouteurs de `Workout` referaient, à chaque séance, les
     * records et la série de jours d'un compte qui disparaît dans la même
     * transaction. Les lignes et les séries suivent par `ON DELETE CASCADE`,
     * puis les exercices personnels, que plus rien n'utilise, suivent le
     * compte.
     */
    #[\Override]
    protected function performDeleteOnModel(): void
    {
        $this->getConnection()->table('workouts')
            ->where('user_id', $this->getKey())
            ->delete();

        parent::performDeleteOnModel();
    }

    /**
     * `password_reset_tokens` range le jeton sous l'adresse de courriel, sans
     * clé étrangère : la ligne survivait au compte, bien après l'expiration
     * du jeton. Le courtier efface celle de l'adresse actuelle, par la
     * connexion par défaut, celle du compte : l'effacement entre dans la
     * transaction. Un jeton demandé sous une ancienne adresse ne mène plus à
     * aucun compte ; il part avec les jetons expirés, que `auth:clear-resets`
     * purge chaque nuit (`routes/console.php`).
     */
    private function effacerSonJetonDeReinitialisation(): void
    {
        Password::deleteToken($this);
    }

    /**
     * Le filtre porte toujours sur le type ET l'identifiant : `users` et
     * `admins` ont chacune leur séquence, un administrateur porte donc
     * souvent l'identifiant d'un compte, et ses rôles comme son audit vivent
     * dans ces mêmes tables.
     */
    private function effacerSesTracesPolymorphes(): void
    {
        foreach (self::TRACES_POLYMORPHES as $trace) {
            [$table, $relation] = explode('.', $trace);

            $this->getConnection()->table($table)
                ->where($relation.'_type', $this->getMorphClass())
                ->where($relation.'_id', $this->getKey())
                ->delete();
        }
    }

    /**
     * Quand un administrateur supprime le compte, une entrée d'audit le dit,
     * et ne dit que cela (#1938) : l'administrateur en est la cause, elle n'a
     * pas de sujet, et ni le nom, ni le courriel, ni l'identifiant du compte
     * n'y figurent. L'entrée « deleted » du compte, effacée avec lui, ne
     * laissait sinon aucune trace de l'action.
     *
     * L'acteur est l'utilisateur de la garde par défaut, celle dont le
     * journal tire aussi ses causes : le panneau en fait la garde `admin` sur
     * chacune de ses requêtes, page, action unitaire ou groupée. Le profil,
     * une commande ou une file n'ont pas d'administrateur pour acteur, et
     * n'écrivent rien. Ici plutôt que dans les actions du panneau, pour que
     * tout chemin d'administration, présent ou futur, l'écrive.
     *
     * L'effacement des traces de ce compte passe avant elle. Celui d'un
     * compte supprimé plus tard, qui porterait l'identifiant de
     * l'administrateur, ne l'atteint pas : il filtre sur le type du compte,
     * et la cause de cette entrée est un administrateur. Elle s'écrit dans la
     * transaction de `delete()`, après la suppression : une suppression
     * annulée ne laisse pas d'entrée. `activitylog:clean` la
     * purge à 180 jours, comme le reste de l'audit.
     */
    private function tracerLaSuppressionParUnAdministrateur(): void
    {
        $acteur = Auth::user();

        if (! $acteur instanceof Admin) {
            return;
        }

        activity()
            ->causedBy($acteur)
            ->event('deleted')
            ->log(self::SUPPRESSION_PAR_UN_ADMINISTRATEUR);
    }
}
