<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Workouts\CreateSetAction;
use App\Actions\Workouts\DemarrerUneSeanceAction;
use App\Actions\Workouts\TerminerLaSeanceAction;
use App\Exceptions\UneSeanceEstDejaEnCours;
use App\Http\Requests\Api\WorkoutFinishRequest;
use App\Http\Requests\Api\WorkoutStoreRequest;
use App\Http\Resources\WorkoutResource;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;

/**
 * Les écritures de la séance elle-même que la page fait en direct ou par la
 * file hors ligne : la démarrer, vide ou depuis un modèle, la terminer
 * (#2049), et réordonner ses exercices. La voie web garde ses formulaires
 * (`workouts.store`, `templates.execute`, `workouts.update`), qui répondent
 * par redirection, interdite à un appel XHR (`.ai/rules/controllers.md`).
 */
class WorkoutController extends Controller
{
    /**
     * Démarre une séance sous la clé `Idempotency-Key` de l'appareil : 201 à
     * la création, 200 au rejeu d'une clé que le compte connaît déjà, close
     * ou non, la séance de la clé rendue telle quelle (`wasRecentlyCreated`,
     * comme les lignes et les séries). Une autre séance en cours refuse le
     * démarrage en 409, avec la raison `seance-deja-en-cours` et
     * l'identifiant de la séance en cours, sans `data` : la file l'annonce
     * comme un refus, et la page démarrée en ligne y renvoie. La clé ne sort
     * jamais dans la réponse (`WorkoutResource` la tait).
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException Si le modèle n'est pas celui du compte.
     */
    public function store(WorkoutStoreRequest $request, DemarrerUneSeanceAction $demarrer): WorkoutResource|JsonResponse
    {
        $this->authorize('create', Workout::class);

        $idDuModele = $request->validated('template_id');
        $modele = null;

        if (is_int($idDuModele) || is_string($idDuModele)) {
            $modele = WorkoutTemplate::query()->findOrFail($idDuModele);
            $this->authorize('view', $modele);
        }

        $debut = $request->validated('started_at');
        $nom = $request->validated('name');
        $cle = CreateSetAction::idempotencyKey(['idempotency_key' => $request->header('Idempotency-Key')]);

        try {
            $seance = $demarrer->execute(
                $this->user(),
                $cle,
                $modele,
                is_string($debut) ? Date::parse($debut) : null,
                is_string($nom) ? $nom : null,
            );
        } catch (UneSeanceEstDejaEnCours $refus) {
            return response()->json([
                'message' => 'Une séance est déjà en cours : terminez-la avant d’en commencer une autre.',
                'raison' => UneSeanceEstDejaEnCours::RAISON,
                'seance' => ['id' => $refus->seanceEnCours->id],
            ], 409);
        }

        return new WorkoutResource($seance);
    }

    /**
     * Termine la séance que le corps nomme, à l'heure du geste sur
     * l'appareil, bornée : 200 et la séance, sa première fin gardée quand
     * elle était déjà close. L'autorisation est `view`, pas `update` : la
     * policy refuse de modifier une séance close, et le rejeu d'une fin déjà
     * arrivée recevrait 403 au lieu de la séance. Le refus d'une séance d'un
     * autre compte vient avant, de la validation (`Rule::exists` du compte).
     *
     * L'invitation aux notifications part dans `meta.proposer_les_notifications`,
     * jamais en donnée flash de la session : un rejeu de la file la ferait
     * surgir sur une page au hasard ; la page qui termine en direct la passe
     * elle-même à l'accueil.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException Si la séance n'est pas celle du compte.
     */
    public function finish(WorkoutFinishRequest $request, TerminerLaSeanceAction $terminer): WorkoutResource
    {
        /** @var Workout $seance un identifiant que la validation a trouvé parmi les séances du compte */
        $seance = Workout::query()->findOrFail($request->validated('workout_id'));
        $this->authorize('view', $seance);

        $fin = $request->validated('ended_at');
        $seance = $terminer->execute($seance, is_string($fin) ? Date::parse($fin) : null);

        return new WorkoutResource($seance)->additional([
            'meta' => ['proposer_les_notifications' => $this->user()->doitRecevoirLInvitationAuxNotifications()],
        ]);
    }

    public function reorderLines(
        \App\Http\Requests\Api\WorkoutLineOrderRequest $request,
        Workout $workout,
        \App\Actions\Workouts\ReorderAction $reordonner
    ): WorkoutResource {
        $this->authorize('update', $workout);

        $reordonner->execute($workout->workoutLines(), (array) $request->validated('lines'), 'lines');

        return new WorkoutResource($workout->load(['workoutLines.exercise', 'workoutLines.sets']));
    }
}
