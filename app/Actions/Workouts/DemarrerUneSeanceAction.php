<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Actions\CreateWorkoutFromTemplateAction;
use App\Exceptions\UneSeanceEstDejaEnCours;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * Démarre une séance, vide ou depuis un modèle, sous le verrou du compte
 * (#2049).
 *
 * La page, la file hors ligne qui rejoue un démarrage fait sans réseau, et
 * un autre appareil du même compte démarrent par ici. Sous le verrou, dans
 * cet ordre :
 *
 * 1. la clé du démarrage, quand elle désigne une séance du compte, la rend,
 *    close ou non : c'est le rejeu d'un démarrage arrivé au serveur, dont la
 *    réponse s'est perdue, et sa propre séance encore en cours n'est pas un
 *    conflit ;
 * 2. une séance du compte sans fin, lue en base, refuse le démarrage
 *    (`UneSeanceEstDejaEnCours`) : jamais dans le cache de deux heures
 *    d'`ActiveWorkoutService`, qu'une séance démarrée ailleurs n'a pas
 *    encore touché ;
 * 3. sinon la séance se crée, à l'instant du geste borné
 *    (`Workout::debutBorne()`).
 *
 * Sans le verrou, deux onglets ou deux appareils lisaient « aucune séance en
 * cours » au même instant et en ouvraient chacun une (#1958, que le contrôle
 * de la page ne fermait que pour des démarrages successifs). C'est le patron
 * des records (`.ai/rules/services.md`) : une transaction ne suffirait pas,
 * avant la séance il n'y a aucune ligne à verrouiller. Le verrou est celui
 * du compte : les autres comptes démarrent sans l'attendre. Quand il manque
 * (expiré, évincé du cache), l'index unique `(user_id, idempotency_key)`
 * tranche entre deux rejeux d'une même clé, et l'action rend la gagnante.
 *
 * La création d'une séance vide ne s'enveloppe pas d'une transaction : un
 * INSERT est atomique, et `Workout::saved` envoie aussitôt les travaux des
 * succès et des objectifs, qui partiraient avant la validation. Le lancement
 * d'un modèle garde la sienne, qui se valide avant que le verrou se rende.
 */
final readonly class DemarrerUneSeanceAction
{
    /**
     * Combien de secondes le verrou d'un démarrage vit au plus. Il se rend à
     * la fin de la section ; cette durée ne sert que si le processus meurt
     * sans le rendre, et couvre de loin le lancement du plus grand modèle.
     */
    public const int VIE_DU_VERROU = 10;

    /**
     * Combien de secondes un démarrage attend le verrou d'un autre avant
     * d'abandonner par une `LockTimeoutException`.
     */
    public const int ATTENTE_DU_VERROU = 10;

    public function __construct(
        private CreateWorkoutAction $creerUneSeance,
        private CreateWorkoutFromTemplateAction $lancerUnModele,
    ) {
    }

    /**
     * La séance que ce démarrage désigne : celle de la clé si le compte en a
     * une, sinon une séance neuve.
     *
     * L'horloge se lit une fois, en tête : le début et le nom par défaut en
     * viennent, quelle que soit l'attente du verrou.
     *
     * @throws UneSeanceEstDejaEnCours Si une autre séance du compte est en cours.
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException Si le verrou du compte reste pris plus de `ATTENTE_DU_VERROU` secondes.
     */
    public function execute(User $user, ?string $cle, ?WorkoutTemplate $modele = null, ?CarbonInterface $debutSurLAppareil = null, ?string $nom = null): Workout
    {
        $maintenant = now();
        $debut = Workout::debutBorne($debutSurLAppareil, $maintenant);

        /** @var Workout */
        return Cache::lock("demarrage-de-seance:{$user->id}", self::VIE_DU_VERROU)
            ->block(self::ATTENTE_DU_VERROU, fn (): Workout => $this->sousLeVerrou($user, $cle, $modele, $debut, $nom));
    }

    /**
     * La clé, puis la séance en cours, puis la création ; la gagnante de la
     * clé quand l'index refuse la création.
     */
    private function sousLeVerrou(User $user, ?string $cle, ?WorkoutTemplate $modele, CarbonInterface $debut, ?string $nom): Workout
    {
        $deLaCle = $this->seanceDeLaCle($user, $cle);

        if ($deLaCle instanceof Workout) {
            return $deLaCle;
        }

        $enCours = Workout::query()
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        if ($enCours instanceof Workout) {
            throw new UneSeanceEstDejaEnCours($enCours);
        }

        try {
            return $modele instanceof WorkoutTemplate
                ? $this->lancerUnModele->execute($user, $modele, $debut, $cle)
                : $this->creerUneSeance->execute($user, $debut, $nom, $cle);
        } catch (UniqueConstraintViolationException $e) {
            // Deux rejeux de la même clé se sont croisés hors du verrou, l'index a tranché.
            $gagnante = $this->seanceDeLaCle($user, $cle);

            if (! $gagnante instanceof Workout) {
                throw $e;
            }

            return $gagnante;
        }
    }

    /**
     * La séance de ce compte qui porte cette clé. Cherchée parmi les séances
     * du compte seulement, jamais dans toute la table : la clé arrive dans un
     * en-tête que le client écrit, et le rejeu de la clé d'un autre ne rend
     * jamais sa séance.
     */
    private function seanceDeLaCle(User $user, ?string $cle): ?Workout
    {
        if ($cle === null) {
            return null;
        }

        return Workout::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $cle)
            ->first();
    }
}
