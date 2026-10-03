<?php

declare(strict_types=1);

namespace App\Models\Traits;

/**
 * Un compte supprimé emporte ce qui le désigne sans clé étrangère (#1935).
 *
 * Les tables liées au compte par `user_id` suivent seules, par
 * `ON DELETE CASCADE`. Celles que `TRACES_POLYMORPHES` énumère le désignent
 * par une relation polymorphe, un couple `*_type` / `*_id` : la base ne sait
 * pas que la ligne parle d'un compte, et rien ne la suivait.
 *
 * Réservé à `User`, qui porte aussi `LogsActivity`.
 */
trait EffaceSesTracesPolymorphes
{
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
     * Supprime le compte et, dans la même transaction, tout ce que
     * `TRACES_POLYMORPHES` lui rattache.
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
     * était activé, elle n'y serait écrite qu'APRÈS l'effacement.
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
                    $this->effacerSesTracesPolymorphes();
                }

                return $supprime;
            });
        } finally {
            $this->enableLoggingModelsEvents = $journalActif;
        }
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
}
