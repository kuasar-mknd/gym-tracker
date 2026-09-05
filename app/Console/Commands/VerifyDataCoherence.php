<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\PersonalRecordService;
use App\Services\StreakService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
        /** @var array<string, callable(): array{int, list<string>}> $controles */
        $controles = [
            'volume des séances' => $this->volumeSeances(...),
            'records rattachés à une série existante' => $this->recordsOrphelins(...),
            'valeur des records' => $this->valeurDesRecords(...),
            'records assis sur une série éligible' => $this->recordsSurSerieInegible(...),
            'date de dernière séance' => $this->dateDerniereSeance(...),
        ];

        if ($this->option('repair')) {
            $this->reconstruireLesRecords();
            $this->recalculerLesVolumes();
            $this->recalculerLesSeries();
        }

        $total = 0;

        foreach ($controles as $intitule => $controle) {
            [$ecarts, $exemples] = $controle();
            $total += $ecarts;

            if ($ecarts === 0) {
                $this->line("  <fg=green>OK</> {$intitule}");

                continue;
            }

            $this->line("  <fg=red>{$ecarts}</> {$intitule}");

            foreach ($exemples as $exemple) {
                $this->line("       {$exemple}");
            }

            if ($ecarts > count($exemples)) {
                $this->line(sprintf('       … et %d autre(s), non cité(s)', $ecarts - count($exemples)));
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
     * Refait les series de jours depuis les seances qui existent.
     *
     * `last_workout_at` etait CONTROLE et jamais REPARE : une seance supprimee
     * avant #1460 laissait la date pointer dans le vide, et rien ne pouvait
     * plus la corriger. Mesure en production le 31/08 : un compte annoncait le
     * 10/08 pour une derniere seance du 24/05.
     *
     * `recalculerDepuisLesFaits()` refait les trois valeurs — la date, la serie
     * en cours et la plus longue — depuis les seances qui restent. C'est deja
     * ce que la suppression d'une seance declenche.
     */
    private function recalculerLesSeries(): void
    {
        $service = app(StreakService::class);
        $recales = 0;

        User::query()
            ->select(['id', 'last_workout_at', 'current_streak', 'longest_streak'])
            ->orderBy('id')
            ->chunkById(500, function (\Illuminate\Database\Eloquent\Collection $utilisateurs) use ($service, &$recales): void {
                foreach ($utilisateurs as $utilisateur) {
                    $reelle = $utilisateur->workouts()->max('started_at');
                    $stockee = $utilisateur->last_workout_at?->toDateTimeString();

                    if ($reelle === $stockee) {
                        continue;
                    }

                    $service->recalculerDepuisLesFaits($utilisateur);
                    $recales++;
                }
            });

        $this->line(sprintf('  <fg=yellow>%d</> série(s) refaite(s) depuis les séances restantes', $recales));
        $this->newLine();
    }

    /**
     * Recale le volume de chaque seance sur ses series reellement faites.
     *
     * Une ecriture qui contourne `recomputeVolume()` laisse le compteur faux ;
     * sans chemin de reparation, le controle nocturne le signalerait chaque
     * nuit sans recours. Le volume d'un utilisateur, lui, n'est plus stocke :
     * il se lit dans ses seances.
     */
    private function recalculerLesVolumes(): void
    {
        $recalees = 0;

        DB::table('workouts')->orderBy('id')->chunkById(500, function (Collection $seances) use (&$recalees): void {
            foreach ($seances as $seance) {
                $reel = (float) DB::table('workout_lines')
                    ->join('sets', 'sets.workout_line_id', '=', 'workout_lines.id')
                    ->where('workout_lines.workout_id', $seance->id)
                    ->where('sets.is_completed', true)
                    ->sum(DB::raw('COALESCE(sets.weight, 0) * COALESCE(sets.reps, 0)'));

                $stocke = is_numeric($seance->workout_volume) ? (float) $seance->workout_volume : 0.0;

                if (abs($stocke - $reel) <= 0.01) {
                    continue;
                }

                DB::table('workouts')->where('id', $seance->id)->update(['workout_volume' => $reel]);
                $recalees++;
            }
        });

        $this->line(sprintf('  <fg=yellow>%d</> séance(s) dont le volume a été recalé', $recalees));
        $this->newLine();
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
            ->where(function (\Illuminate\Database\Eloquent\Builder $arefaire): void {
                $arefaire->whereNull('set_id')
                    ->orWhereNotIn('set_id', DB::table('sets')->select('id'))
                    // Et ceux qui pointent sur une serie que les regles
                    // n'acceptent pas : ils ne sont pas detaches, mais ils
                    // annoncent un poids que personne n'a souleve.
                    ->orWhereIn('set_id', DB::table('sets')->select('id')->where(function (\Illuminate\Database\Query\Builder $inegible): void {
                        $inegible->where('is_warmup', true)
                            ->orWhere('is_completed', false)
                            ->orWhereNull('weight')
                            ->orWhere('weight', '<=', 0)
                            ->orWhereNull('reps')
                            ->orWhere('reps', '<=', 0);
                    }))
                    /*
                     * Et ceux dont la VALEUR ne correspond plus a leur serie.
                     *
                     * Ils pointent sur une serie qui existe et que les regles
                     * acceptent : ni detaches, ni inegibles. Le controle les
                     * voyait, la reparation non — un poids corrige apres coup
                     * laissait le record sur l'ancien chiffre, et rien ne
                     * pouvait plus le remettre d'aplomb.
                     *
                     * Le tri sur `max_weight` suffit a declencher : `recompute()`
                     * refait les trois types du couple (utilisateur, exercice)
                     * d'un seul coup.
                     */
                    ->orWhere(function (\Illuminate\Database\Eloquent\Builder $valeurFausse): void {
                        $valeurFausse->where('type', 'max_weight')
                            ->whereIn('set_id', DB::table('sets')->select('id'))
                            ->whereRaw('ABS(personal_records.value - COALESCE((select weight from sets where sets.id = personal_records.set_id), 0)) > 0.01');
                    });
            })
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
     * `workouts.workout_volume` contre la somme des series de la seance.
     *
     * @return array{int, list<string>}
     */
    private function volumeSeances(): array
    {
        $requete = DB::table('workouts')
            ->leftJoinSub(
                DB::table('workout_lines')
                    ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
                    // Meme filtre que `Workout::recomputeVolume()` : le volume
                    // compte les series faites, pas celles qui attendent (#1499).
                    // Sans lui, une seance dont une serie n'est pas cochee
                    // serait signalee divergente alors qu'elle est juste.
                    ->where('sets.is_completed', true)
                    ->selectRaw('workout_lines.workout_id, SUM(COALESCE(sets.weight, 0) * COALESCE(sets.reps, 0)) as reel')
                    ->groupBy('workout_lines.workout_id'),
                'calcul',
                'calcul.workout_id',
                '=',
                'workouts.id',
            )
            ->selectRaw('workouts.id, workouts.workout_volume as stocke, COALESCE(calcul.reel, 0) as reel')
            ->whereRaw('ABS(workouts.workout_volume - COALESCE(calcul.reel, 0)) > 0.01');

        return $this->ecarts($requete, fn (array $ligne): string => sprintf(
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
     * @return array{int, list<string>}
     */
    private function recordsOrphelins(): array
    {
        $requete = DB::table('personal_records')
            ->whereNull('set_id')
            ->orWhereNotIn('set_id', DB::table('sets')->select('id'))
            ->select(['id', 'user_id', 'type', 'value']);

        return $this->ecarts($requete, fn (array $ligne): string => sprintf(
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
     * @return array{int, list<string>}
     */
    private function valeurDesRecords(): array
    {
        $requete = DB::table('personal_records')
            ->join('sets', 'sets.id', '=', 'personal_records.set_id')
            ->where('personal_records.type', 'max_weight')
            ->whereRaw('ABS(personal_records.value - COALESCE(sets.weight, 0)) > 0.01')
            ->select([
                'personal_records.id',
                'personal_records.value as stocke',
                'sets.weight as reel',
            ]);

        return $this->ecarts($requete, fn (array $ligne): string => sprintf(
            'record %s : annonce %s, la série porte %s',
            $this->colonne($ligne, 'id'),
            $this->colonne($ligne, 'stocke'),
            $this->colonne($ligne, 'reel'),
        ));
    }

    /**
     * Un record doit s'appuyer sur une serie que les regles ACCEPTENT.
     *
     * Le controle voisin compare la valeur du record au poids de sa serie ; il
     * ne demande jamais si cette serie comptait. Or l'interface cree chaque
     * ligne DECOCHEE, puis l'utilisateur la coche une fois faite : un poids
     * saisi et jamais coche est une intention, pas un souleve. #1615 l'a exclu
     * du calcul, mais les records deja poses dessus restaient debout — ils ne
     * sont pas detaches, donc `reconstruireLesRecords()` ne les voyait pas non
     * plus.
     *
     * Les memes regles que `PersonalRecordService::CLASSEMENT`, et pour la meme
     * raison : deux definitions de l'eligibilite divergeraient.
     *
     * @return array{int, list<string>}
     */
    private function recordsSurSerieInegible(): array
    {
        $requete = DB::table('personal_records')
            ->join('sets', 'sets.id', '=', 'personal_records.set_id')
            ->where(function (\Illuminate\Database\Query\Builder $inegible): void {
                $inegible->where('sets.is_warmup', true)
                    ->orWhere('sets.is_completed', false)
                    ->orWhereNull('sets.weight')
                    ->orWhere('sets.weight', '<=', 0)
                    ->orWhereNull('sets.reps')
                    ->orWhere('sets.reps', '<=', 0);
            })
            ->select([
                'personal_records.id',
                'personal_records.type',
                'personal_records.value',
                'personal_records.user_id',
            ]);

        return $this->ecarts($requete, fn (array $ligne): string => sprintf(
            "record %s (%s, %s) de l'utilisateur %s s'appuie sur une série qui ne compte pas",
            $this->colonne($ligne, 'id'),
            $this->colonne($ligne, 'type'),
            $this->colonne($ligne, 'value'),
            $this->colonne($ligne, 'user_id'),
        ));
    }

    /**
     * `users.last_workout_at` contre la seance la plus recente.
     *
     * C'est la seule memoire du calcul de serie. Une fois en retard, l'ecart
     * calcule a la seance suivante part d'une date fausse et casse une serie
     * pourtant continue (#1459).
     *
     * @return array{int, list<string>}
     */
    private function dateDerniereSeance(): array
    {
        $requete = DB::table('users')
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
            ->whereRaw('NOT (users.last_workout_at <=> calcul.reel)');

        return $this->ecarts($requete, fn (array $ligne): string => sprintf(
            'utilisateur %s : stocké %s, dernière séance %s',
            $this->colonne($ligne, 'id'),
            $this->colonne($ligne, 'stocke'),
            $this->colonne($ligne, 'reel'),
        ));
    }

    /**
     * Le NOMBRE d'ecarts, et quelques-uns decrits.
     *
     * Les deux etaient confondus : `--limit` bornait la requete, et le compte
     * affiche etait celui des lignes rendues. Cinq cents utilisateurs derivant
     * s'annoncaient donc « 5 ».
     *
     * @param  \Illuminate\Database\Query\Builder  $requete
     * @param  callable(array<array-key, mixed>): string  $decrire
     * @return array{int, list<string>}
     */
    private function ecarts($requete, callable $decrire): array
    {
        $nombre = (clone $requete)->count();
        $descriptions = [];

        foreach ($requete->limit($this->limite())->get() as $ligne) {
            $descriptions[] = $decrire(get_object_vars($ligne));
        }

        return [$nombre, $descriptions];
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
