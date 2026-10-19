<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $description
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WorkoutTemplateLine> $workoutTemplateLines
 */
class WorkoutTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\WorkoutTemplateFactory> */
    use HasFactory;

    /**
     * Le plus d'exercices qu'un modèle accepte.
     */
    public const int EXERCICES_MAX = 50;

    /**
     * Le plus de séries qu'un exercice d'un modèle accepte. Chaque série
     * devient une ligne de `workout_template_sets`, puis une série de la
     * séance qui démarre du modèle : cinquante couvrent les méthodes les plus
     * longues (dix fois dix, séries dégressives) et une séance recopiée en
     * modèle, et bornent un modèle à 2 500 séries, insérées en vingt-cinq
     * paquets d'une transaction.
     */
    public const int SERIES_MAX_PAR_EXERCICE = 50;

    /**
     * Les plafonds d'un modèle, que son formulaire reçoit pour ne pas
     * proposer d'ajouter un exercice ou une série que la requête refuserait.
     *
     * @return array{exercices: int, seriesParExercice: int}
     */
    public static function bornes(): array
    {
        return [
            'exercices' => self::EXERCICES_MAX,
            'seriesParExercice' => self::SERIES_MAX_PAR_EXERCICE,
        ];
    }

    /**
     * La clé d'idempotence de la ligne qu'un lancement du modèle crée à ce
     * rang (#2049) : `modele-ligne:{rang}:{exercice}`.
     *
     * Un modèle lancé sans réponse du serveur, la file le rejoue écriture par
     * écriture (la séance, puis chaque ligne et chaque série), et le serveur
     * a pu l'exécuter déjà. Les lignes et les séries qu'il a créées portent
     * ces clés, et la page des modèles donne les mêmes à la file
     * (`FetchWorkoutTemplatesAction::lancements()`) : chaque écriture rejouée
     * rend alors celle qui existe, au lieu de la doubler. Une seule source
     * pour les deux, ici, plutôt qu'un calcul recopié en JavaScript.
     *
     * Le rang part de zéro, dans l'ordre du modèle (`order`, puis `id`).
     * L'exercice entre dans la clé : un modèle modifié entre le lancement et
     * le rejeu, dont le rang a changé d'exercice, crée une ligne de plus,
     * jamais une série rattachée à un autre exercice. La clé ne dépend pas de
     * celle de la séance : elle n'est cherchée que dans cette séance
     * (`CreateWorkoutLineAction`), et garde ainsi une longueur fixe, sous les
     * 64 caractères ASCII sans espace qu'admet `CreateSetAction::idempotencyKey()`.
     */
    public static function cleDUneLigneLancee(int $rang, int $exerciceId): string
    {
        return "modele-ligne:{$rang}:{$exerciceId}";
    }

    /**
     * La clé d'idempotence de la série qu'un lancement du modèle crée à ce
     * rang de cette ligne (#2049) : `modele-serie:{ligne}:{série}`, rangs
     * partis de zéro dans l'ordre du modèle. Elle n'est cherchée que dans sa
     * ligne (`CreateSetAction`), dont la clé porte déjà l'exercice ; voir
     * `cleDUneLigneLancee()`.
     */
    public static function cleDUneSerieLancee(int $rangLigne, int $rangSerie): string
    {
        return "modele-serie:{$rangLigne}:{$rangSerie}";
    }

    #[\Override]
    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\WorkoutTemplateLine, $this>
     */
    public function workoutTemplateLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkoutTemplateLine::class)->orderBy('order')->orderBy('id');
    }
}
