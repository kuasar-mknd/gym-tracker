<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PersonalRecord;
use App\Services\PersonalRecordService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Compare chaque valeur derivee a ce dont elle derive, sur les donnees reelles.
 *
 * Les quatre defauts trouves le 18/08 avaient tous la meme forme : une valeur
 * dénormalisee qui ne correspondait plus a sa source. Un record a 500 kg pointant
 * sur une serie supprimee (#1476), un `last_workout_at` en retard d'une seance
 * (#1459), une progression d'objectif restee a zero (#1478). Aucun n'a ete trouve
 * en lisant le code — mais tous auraient ete visibles ici, immediatement.
 *
 * C'est la difference entre verifier un scenario qu'on a su imaginer et observer
 * ce qui existe. Un test ne connait que les chemins qu'on lui a decrits ; ce
 * controle voit aussi ce que produisent une cascade en base, un job rejoue, une
 * ecriture concurrente ou une migration passee.
 *
 * Il ne corrige rien, a dessein : reparer masquerait la cause, et c'est la cause
 * qui interesse. Il compte, il nomme, et il sort en erreur.
 */
class VerifyDataCoherence extends Command
{
    #[\Override]
    protected $signature = 'app:verify-data-coherence
        {--limit=5 : Nombre d\'identifiants cités par écart}
        {--repair : Reconstruit les records détachés depuis les séries qui restent}';

    #[\Override]
    protected $description = 'Compare les valeurs dérivées (volumes, records, séries, objectifs) à leur source';

    public function handle(): int
    {
        /** @var array<string, callable(): list<string>> $controles */
        $controles = [
            'volume total des utilisateurs' => $this->volumeUtilisateurs(...),
            'volume des séances' => $this->volumeSeances(...),
            'records rattachés à une série existante' => $this->recordsOrphelins(...),
            'valeur des records' => $this->valeurDesRecords(...),
            'date de dernière séance' => $this->dateDerniereSeance(...),
        ];

        if ($this->option('repair')) {
            $this->reconstruireLesRecords();
        }

        $total = 0;

        foreach ($controles as $intitule => $controle) {
            $exemples = $controle();
            $ecarts = count($exemples);
            $total += $ecarts;

            if ($ecarts === 0) {
                $this->line("  <fg=green>OK</> {$intitule}");

                continue;
            }

            $this->line("  <fg=red>{$ecarts}</> {$intitule}");

            foreach ($exemples as $exemple) {
                $this->line("       {$exemple}");
            }
        }

        if ($total === 0) {
            $this->newLine();
            $this->info('Aucun écart : les valeurs dérivées correspondent à leur source.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error("{$total} écart(s). Une valeur dérivée qui ment ne lève aucune erreur ailleurs.");

        return self::FAILURE;
    }

    /**
     * Reconstruit les records des exercices dont un record s'est detache.
     *
     * Sans chemin de reparation, un controle qui trouve un ecart heritee reste
     * rouge indefiniment — et un controle qui ne peut pas passer au vert finit
     * desactive. C'est le sort de toutes les portes qu'on ne peut pas franchir.
     *
     * La reparation n'invente rien : elle appelle `recompute()`, exactement ce
     * que l'application execute deja quand une serie disparait (#1476). Les
     * records sont reconstruits depuis les series qui restent, ou supprimes s'il
     * n'en reste aucune.
     */
    private function reconstruireLesRecords(): void
    {
        $detaches = PersonalRecord::query()
            ->with('user')
            ->whereNull('set_id')
            ->orWhereNotIn('set_id', DB::table('sets')->select('id'))
            ->get();

        if ($detaches->isEmpty()) {
            $this->line('  <fg=green>OK</> aucun record à reconstruire');
            $this->newLine();

            return;
        }

        $service = app(PersonalRecordService::class);
        $faits = [];

        foreach ($detaches as $record) {
            $user = $record->user;
            $exerciseId = $record->exercise_id;

            // Un couple (utilisateur, exercice) ne se reconstruit qu'une fois :
            // `recompute()` traite les trois types d'un coup.
            $cle = "{$record->user_id}:{$exerciseId}";

            if ($user === null || isset($faits[$cle])) {
                continue;
            }

            $service->recompute($user, $exerciseId);
            $faits[$cle] = true;
        }

        $this->line(sprintf(
            '  <fg=yellow>%d</> exercice(s) reconstruit(s) depuis les séries restantes',
            count($faits),
        ));
        $this->newLine();
    }

    /**
     * Le nombre d'identifiants cites par ecart.
     */
    private function limite(): int
    {
        $limite = $this->option('limit');

        return is_numeric($limite) ? max(1, (int) $limite) : 5;
    }

    /**
     * `users.total_volume` contre la somme des series de l'utilisateur.
     *
     * Le compteur est maintenu par `increment()` et `decrement()`, donc par des
     * deltas. Toute ecriture qui contourne ces appels — une cascade en base, une
     * suppression en masse — le laisse derriver sans rien signaler.
     *
     * @return list<string>
     */
    private function volumeUtilisateurs(): array
    {
        $lignes = DB::table('users')
            ->leftJoinSub(
                DB::table('workouts')
                    ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
                    ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
                    ->selectRaw('workouts.user_id, SUM(COALESCE(sets.weight, 0) * COALESCE(sets.reps, 0)) as reel')
                    ->groupBy('workouts.user_id'),
                'calcul',
                'calcul.user_id',
                '=',
                'users.id',
            )
            ->selectRaw('users.id, users.total_volume as stocke, COALESCE(calcul.reel, 0) as reel')
            // Les volumes sont des decimaux : un centieme d'ecart vient de
            // l'arrondi, pas d'une derive.
            ->whereRaw('ABS(users.total_volume - COALESCE(calcul.reel, 0)) > 0.01')
            ->limit($this->limite())
            ->get();

        return $this->decrire($lignes, fn (array $ligne): string => sprintf(
            'utilisateur %s : stocké %s, calculé %s',
            $this->colonne($ligne, 'id'),
            $this->colonne($ligne, 'stocke'),
            $this->colonne($ligne, 'reel'),
        ));
    }

    /**
     * `workouts.workout_volume` contre la somme des series de la seance.
     *
     * @return list<string>
     */
    private function volumeSeances(): array
    {
        $lignes = DB::table('workouts')
            ->leftJoinSub(
                DB::table('workout_lines')
                    ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
                    ->selectRaw('workout_lines.workout_id, SUM(COALESCE(sets.weight, 0) * COALESCE(sets.reps, 0)) as reel')
                    ->groupBy('workout_lines.workout_id'),
                'calcul',
                'calcul.workout_id',
                '=',
                'workouts.id',
            )
            ->selectRaw('workouts.id, workouts.workout_volume as stocke, COALESCE(calcul.reel, 0) as reel')
            ->whereRaw('ABS(workouts.workout_volume - COALESCE(calcul.reel, 0)) > 0.01')
            ->limit($this->limite())
            ->get();

        return $this->decrire($lignes, fn (array $ligne): string => sprintf(
            'séance %s : stocké %s, calculé %s',
            $this->colonne($ligne, 'id'),
            $this->colonne($ligne, 'stocke'),
            $this->colonne($ligne, 'reel'),
        ));
    }

    /**
     * Un record doit pointer sur une serie qui existe encore.
     *
     * `personal_records.set_id` est en `ON DELETE SET NULL` : la ligne survit a
     * la disparition de la serie qui la portait, simplement detachee. C'est
     * exactement le defaut de #1476, et il ne se voit d'aucune autre facon.
     *
     * @return list<string>
     */
    private function recordsOrphelins(): array
    {
        $lignes = DB::table('personal_records')
            ->whereNull('set_id')
            ->orWhereNotIn('set_id', DB::table('sets')->select('id'))
            ->select(['id', 'user_id', 'type', 'value'])
            ->limit($this->limite())
            ->get();

        return $this->decrire($lignes, fn (array $ligne): string => sprintf(
            "record %s (%s, %s) de l'utilisateur %s ne pointe sur aucune série",
            $this->colonne($ligne, 'id'),
            $this->colonne($ligne, 'type'),
            $this->colonne($ligne, 'value'),
            $this->colonne($ligne, 'user_id'),
        ));
    }

    /**
     * La valeur d'un record de poids doit etre celle de la serie qu'il designe.
     *
     * Un record juste en valeur et faux en provenance reste faux : c'est cette
     * incoherence-la qui laissait un chiffre fantome remonter dans les succes.
     *
     * @return list<string>
     */
    private function valeurDesRecords(): array
    {
        $lignes = DB::table('personal_records')
            ->join('sets', 'sets.id', '=', 'personal_records.set_id')
            ->where('personal_records.type', 'max_weight')
            ->whereRaw('ABS(personal_records.value - COALESCE(sets.weight, 0)) > 0.01')
            ->select([
                'personal_records.id',
                'personal_records.value as stocke',
                'sets.weight as reel',
            ])
            ->limit($this->limite())
            ->get();

        return $this->decrire($lignes, fn (array $ligne): string => sprintf(
            'record %s : annonce %s, la série porte %s',
            $this->colonne($ligne, 'id'),
            $this->colonne($ligne, 'stocke'),
            $this->colonne($ligne, 'reel'),
        ));
    }

    /**
     * `users.last_workout_at` contre la seance la plus recente.
     *
     * C'est la seule memoire du calcul de serie. Une fois en retard, l'ecart
     * calcule a la seance suivante part d'une date fausse et casse une serie
     * pourtant continue (#1459).
     *
     * @return list<string>
     */
    private function dateDerniereSeance(): array
    {
        $lignes = DB::table('users')
            ->leftJoinSub(
                DB::table('workouts')
                    ->selectRaw('user_id, MAX(started_at) as reel')
                    ->groupBy('user_id'),
                'calcul',
                'calcul.user_id',
                '=',
                'users.id',
            )
            ->selectRaw('users.id, users.last_workout_at as stocke, calcul.reel')
            // Un compte sans aucune seance doit avoir une date nulle, et une
            // date posee doit correspondre a la seance la plus recente.
            ->whereRaw('NOT (users.last_workout_at <=> calcul.reel)')
            ->limit($this->limite())
            ->get();

        return $this->decrire($lignes, fn (array $ligne): string => sprintf(
            'utilisateur %s : stocké %s, dernière séance %s',
            $this->colonne($ligne, 'id'),
            $this->colonne($ligne, 'stocke'),
            $this->colonne($ligne, 'reel'),
        ));
    }

    /**
     * Les descriptions d'ecart, une par ligne rendue par la requete.
     *
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $lignes
     * @param  callable(array<array-key, mixed>): string  $decrire
     * @return list<string>
     */
    private function decrire($lignes, callable $decrire): array
    {
        $descriptions = [];

        foreach ($lignes as $ligne) {
            $descriptions[] = $decrire(get_object_vars($ligne));
        }

        return $descriptions;
    }

    /**
     * Une colonne de resultat, rendue lisible.
     *
     * Les lignes d'une requete brute portent des valeurs non typees. Les lire par
     * `get_object_vars()` plutot que par acces direct evite d'ajouter au baseline
     * PHPStan la famille meme que #1482 cherche a drainer.
     *
     * @param  array<array-key, mixed>  $ligne
     */
    private function colonne(array $ligne, string $nom, string $siVide = 'aucune'): string
    {
        $valeur = $ligne[$nom] ?? null;

        return is_scalar($valeur) ? (string) $valeur : $siVide;
    }
}
