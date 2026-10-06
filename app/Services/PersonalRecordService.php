<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PersonalRecordType;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Notifications\PersonalRecordAchieved;
use App\Traits\CalculatesOneRepMax;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tient les records personnels : poids maximal, 1RM estimé et meilleur volume
 * sur une série, pour chaque exercice.
 *
 * Les records se mettent à jour quand une série est validée, et se
 * reconstruisent quand la série qui les portait change ou disparaît. La
 * notification d'un nouveau record part d'ici aussi.
 *
 * Toute lecture-comparaison-écriture des records d'un exercice se fait sous
 * un verrou atomique du couple (compte, exercice) : voir `sousLeVerrou()`.
 * Les lectures qui décident d'une écriture aussi : la série que l'on
 * synchronise y est relue, et ce qu'une série corrigée ou supprimée détient
 * s'y établit, dans la section même qui reconstruit.
 */
final class PersonalRecordService
{
    use CalculatesOneRepMax;

    /**
     * Combien de secondes un verrou de records vit au plus.
     *
     * Le verrou se rend à la fin de la section qu'il protège : cette durée ne
     * sert que si le processus qui le tient meurt sans le rendre. Elle couvre
     * de loin la plus longue section, deux lectures et quatre écritures.
     */
    private const int VIE_DU_VERROU = 30;

    /**
     * Combien de secondes une écriture attend le verrou d'un autre processus
     * avant d'abandonner par une `LockTimeoutException`.
     */
    private const int ATTENTE_DU_VERROU = 15;

    /**
     * Confronte une série terminée aux records de son exercice, et crée ou met
     * à jour ceux qu'elle bat.
     *
     * @param  \App\Models\Set  $set  La série à évaluer.
     * @param  \App\Models\User|null  $user  L'auteur de la série ; déduit de la série si absent.
     */
    public function synchroniserLesRecordsDeLaSerie(Set $set, ?User $user = null): void
    {
        if ($this->shouldSkipSync($set)) {
            return;
        }

        $set->loadMissing(['workoutLine.workout.user']);

        /*
         * Trois gardes sont parties d'ici : `! $workout`, `! $user` et
         * `! $idExercice`. Aucune ne pouvait se declencher.
         *
         * `sets.workout_line_id`, `workout_lines.workout_id`,
         * `workout_lines.exercise_id` et `workouts.user_id` sont toutes NOT NULL
         * et porteuses d'une contrainte de cle etrangere : la chaine ne peut pas
         * rendre null, et un `exercise_id` a 0 ne reference rien.
         *
         * Le `! $user` se refutait tout seul : `loadMissing()` etait appele sur
         * `$user` a la ligne precedente. Si la valeur avait pu etre nulle, on
         * aurait plante avant d'arriver au test cense l'empecher.
         *
         * Les gardes homologues de `refreshFor()` restent, elles : cette
         * methode-la s'execute sur `deleted`, ou la ligne parente peut avoir
         * disparu malgre la contrainte — c'est le defaut corrige en #1476.
         */
        $user ??= $set->workoutLine->workout->user;
        $user->loadMissing('notificationPreferences');

        $this->processUpdates($user, $set->workoutLine->exercise_id, $set);
    }

    /**
     * Établit un record d'un type donné, s'il dépasse celui qui tient.
     *
     * @param  \App\Models\User  $user  L'auteur du record.
     * @param  int  $idExercice  L'exercice concerné.
     * @param  string  $type  Le type de record : 'max_weight', 'max_1rm' ou 'max_volume_set'.
     * @param  float  $value  La valeur du record.
     * @param  float|null  $secondary  La valeur qui l'accompagne, par exemple les répétitions du poids maximal.
     * @param  \App\Models\Set  $set  La série qui l'a établi.
     * @param  \App\Models\PersonalRecord|null  $pr  Le record en place, s'il y en a un.
     * @return \App\Models\PersonalRecord|null Le record que la série vient d'établir, à annoncer une fois le verrou rendu.
     */
    protected function update(User $user, int $idExercice, string $type, float $value, ?float $secondary, Set $set, ?PersonalRecord $pr): ?PersonalRecord
    {
        if ($pr !== null && $value <= $pr->value) {
            return null;
        }

        $pr ??= new PersonalRecord(['user_id' => $user->id, 'exercise_id' => $idExercice, 'type' => $type]);
        /*
         * `secondary_value` et `workout_id` sont ecrases juste apres.
         *
         * `refreshRecordsHeldBy()` s'execute dans la meme sauvegarde de serie
         * (AppServiceProvider::registerSetEvents) et passe par `recompute()`,
         * qui remplit les memes cinq champs ligne 179. Les retirer d'ici ne
         * change donc rien d'observable, ce qui rend leurs mutants equivalents
         * plutot que non couverts — un test qui pretendrait les tuer verifierait
         * en realite l'ecriture de l'autre chemin.
         *
         * Ils restent la parce que `update()` doit se tenir seul : rien ne
         * garantit que le second chemin l'accompagnera toujours.
         *
         * @pest-mutate-ignore RemoveArrayItem
         */
        $pr->fill(['value' => $value, 'secondary_value' => $secondary, 'workout_id' => $set->workoutLine->workout_id, 'set_id' => $set->id, 'achieved_at' => now()])->save();

        return $pr;
    }

    /**
     * Les séries qui ne peuvent pas établir de record.
     *
     * @param  \App\Models\Set  $set  La série à examiner.
     */
    private function shouldSkipSync(Set $set): bool
    {
        /*
         * `! $set->weight` etait vrai pour null comme pour zero. Les deux cas
         * doivent bien etre ignores — une serie a zero kilo ou zero repetition
         * ne produit que des records nuls — mais le code disait « non renseigne »
         * en pensant « nul ou zero ». Un poids negatif, lui, passait.
         *
         * `is_completed` manquait, et c'est le plus grave : l'interface cree
         * chaque serie decochee, puis l'utilisateur la coche une fois faite. Un
         * poids saisi mais jamais coche — une intention, pas un souleve —
         * devenait donc un record personnel.
         */
        return $set->is_warmup
            || ! $set->is_completed
            || $set->weight === null || $set->weight <= 0.0
            || $set->reps === null || $set->reps <= 0;
    }

    /**
     * Une seule passe sur les series, trois classements.
     *
     * Trois requetes `ORDER BY … LIMIT 1` seraient trois balayages : aucun index
     * ne couvre un tri sur une expression, donc chacune relit tout. Les fonctions
     * de fenetrage classent les trois criteres en une lecture et ne rendent que
     * les lignes qui gagnent — trois au plus.
     */
    private const string CLASSEMENT = <<<'SQL'
        select * from (
            select sets.id, sets.weight, sets.reps, sets.created_at, workout_lines.workout_id,
                   row_number() over (order by sets.weight desc, sets.id asc) as rang_max_weight,
                   row_number() over (order by (case when sets.reps <= 1 then sets.weight else sets.weight * (1 + sets.reps / 30) end) desc, sets.id asc) as rang_max_1rm,
                   row_number() over (order by sets.weight * sets.reps desc, sets.id asc) as rang_max_volume_set
              from sets
              join workout_lines on workout_lines.id = sets.workout_line_id
             where workout_lines.user_id = ?
               and workout_lines.exercise_id = ?
               and sets.is_warmup = 0
               and sets.is_completed = 1
               and sets.weight > 0
               and sets.reps > 0
        ) as classees
        where rang_max_weight = 1 or rang_max_1rm = 1 or rang_max_volume_set = 1
        SQL;

    /**
     * Reconstruit les records d'un exercice à partir des séries qui existent
     * vraiment.
     *
     * `update()` ne fait que monter un record, et rien ne l'abaissait jamais.
     * Un seul poids mal saisi — 500 pour 50 — devenait le record de l'exercice
     * pour de bon : corriger la série n'y changeait rien, la supprimer non plus,
     * et le chiffre restait affiché sur le profil.
     *
     * Appelé seulement quand la série derrière un record change ou disparaît :
     * le coût est payé sur l'événement rare, pas à chaque enregistrement.
     *
     * Seuls les types suivis (`PersonalRecordType::SUIVIS`) sont reconstruits ;
     * une ligne d'un type hérité n'est ni recalculée ni supprimée, c'est le
     * contrôle de cohérence qui la signale.
     *
     * Sous le verrou du couple (compte, exercice) (#1984) : la requête web qui
     * corrige une série reconstruit pendant qu'un processus de file
     * synchronise une autre série du même exercice, et chacun écrivait sur
     * une lecture que l'autre venait de rendre fausse.
     *
     * @param  list<string>|null  $types  Limite la reconstruction à ces records.
     *                                    Null les prend tous, ce qui est juste quand une série a disparu et
     *                                    que plus rien ne dit quels records elle détenait.
     */
    public function recompute(User $user, int $idExercice, ?array $types = null): void
    {
        $this->sousLeVerrou($user->id, $idExercice, function () use ($user, $idExercice, $types): void {
            $this->reconstruire($user, $idExercice, $types);
        });
    }

    /**
     * Le corps de `recompute()`, sans le verrou : l'appelant le tient déjà.
     *
     * Le verrou n'est pas réentrant. `refreshRecordsHeldBy()` et `refreshFor()`
     * décident de ce qu'il faut reconstruire sous le verrou, puis
     * reconstruisent dans la même section : passer par `recompute()`
     * attendrait leur propre verrou jusqu'au délai.
     *
     * @param  list<string>|null  $types  Limite la reconstruction à ces records ; null les prend tous.
     */
    private function reconstruire(User $user, int $idExercice, ?array $types): void
    {
        $gagnantes = $this->gagnantes($user->id, $idExercice);

        /*
         * Les doublons sont supprimes avant d'indexer.
         *
         * `keyBy()` ne garde que le DERNIER d'une clef repetee : sur deux
         * records du meme type, le premier n'etait ni mis a jour ni supprime,
         * et annoncait indefiniment une valeur que plus rien ne soutenait —
         * y compris une serie jamais cochee. Constate en production le 31/08.
         *
         * `un_seul_record_par_type` pose la contrainte qui l'empeche desormais.
         * Ce nettoyage reste parce qu'une base d'avant la contrainte doit
         * pouvoir se reparer, et parce que `recompute()` ne doit dependre
         * d'aucune garantie qu'il ne verifie pas lui-meme.
         */
        $tous = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->where('exercise_id', $idExercice)
            ->orderBy('id')
            ->get();

        /** @var array<string, PersonalRecord> $records */
        $records = [];

        // Les records a retirer partent en une seule instruction a la fin :
        // chaque `delete()` separe coutait une ecriture sur le serveur.
        /** @var list<int> $aSupprimer */
        $aSupprimer = [];

        foreach ($tous as $existant) {
            $type = $existant->type->value;

            // Trie par `id` : on garde le plus recent, comme la migration.
            if (isset($records[$type])) {
                $aSupprimer[] = $records[$type]->id;
            }

            $records[$type] = $existant;
        }

        foreach (PersonalRecordType::SUIVIS as $type) {
            if ($types !== null && ! in_array($type, $types, true)) {
                continue;
            }

            /** @var PersonalRecord|null $record */
            $record = $records[$type] ?? null;
            $meilleure = $gagnantes[$type] ?? null;

            if ($meilleure === null) {
                // Plus une seule série ne qualifie : le record ne tient plus.
                if ($record !== null) {
                    $aSupprimer[] = $record->id;
                }

                continue;
            }

            [$valeur, $secondaire] = $this->mesurer($type, $meilleure['poids'], $meilleure['repetitions']);

            $record ??= new PersonalRecord(['user_id' => $user->id, 'exercise_id' => $idExercice, 'type' => $type]);

            /**
             * Aucune notification ici. C'est une correction, pas un exploit —
             * annoncer un record personnel à quelqu'un qui vient de rattraper
             * une faute de frappe serait pire que de se taire.
             */
            $record->fill([
                'value' => $valeur,
                'secondary_value' => $secondaire,
                'workout_id' => $meilleure['seance'],
                'set_id' => $meilleure['id'],
                'achieved_at' => $meilleure['obtenu'] ?? now(),
            ])->save();
        }

        if ($aSupprimer !== []) {
            PersonalRecord::query()->whereKey($aSupprimer)->delete();
        }
    }

    /**
     * Ce que `recompute()` écrirait pour chaque type suivi : la valeur, et la
     * série gagnante qui la donne. Un type sans série éligible n'y figure pas.
     *
     * Le contrôle de cohérence y compare chaque record (#1984) : le même
     * classement et la même mesure que la reconstruction, pour qu'une
     * seconde définition du meilleur ne diverge pas de la première.
     *
     * @return array<string, array{valeur: float, serie: int}>
     */
    public function recordsAttendus(int $idUtilisateur, int $idExercice): array
    {
        $attendus = [];

        foreach ($this->gagnantes($idUtilisateur, $idExercice) as $type => $meilleure) {
            [$valeur] = $this->mesurer($type, $meilleure['poids'], $meilleure['repetitions']);

            $attendus[$type] = ['valeur' => $valeur, 'serie' => $meilleure['id']];
        }

        return $attendus;
    }

    /**
     * La serie gagnante de chaque type, par type.
     *
     * @return array<string, array{id: int, poids: float, repetitions: int, seance: int, obtenu: string|null}>
     */
    private function gagnantes(int $idUtilisateur, int $idExercice): array
    {
        $par = [];

        foreach (DB::select(self::CLASSEMENT, [$idUtilisateur, $idExercice]) as $workoutLine) {
            if (! is_object($workoutLine)) {
                continue;
            }

            $champs = get_object_vars($workoutLine);

            foreach (PersonalRecordType::SUIVIS as $type) {
                if (self::entier($champs['rang_'.$type] ?? null) !== 1) {
                    continue;
                }

                $obtenu = $champs['created_at'] ?? null;

                $par[$type] = [
                    'id' => self::entier($champs['id'] ?? null),
                    'poids' => self::flottant($champs['weight'] ?? null),
                    'repetitions' => self::entier($champs['reps'] ?? null),
                    'seance' => self::entier($champs['workout_id'] ?? null),
                    'obtenu' => is_string($obtenu) ? $obtenu : null,
                ];
            }
        }

        return $par;
    }

    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    private static function flottant(mixed $valeur): float
    {
        return is_numeric($valeur) ? (float) $valeur : 0.0;
    }

    /**
     * @return array{0: float, 1: float|null}
     */
    private function mesurer(string $type, float $poids, int $repetitions): array
    {
        return match ($type) {
            'max_weight' => [$poids, (float) $repetitions],
            'max_1rm' => [$this->calculate1RM($poids, $repetitions), $poids],
            default => [$poids * $repetitions, null],
        };
    }

    /** Retient ce que la serie detient, tant que la base le sait encore. */
    public function retenirTypesDetenus(Set $set): void
    {
        $set->setAttribute('records_detenus', $this->typesDetenusPar($set));
    }

    /** @return list<string>|null */
    public function typesRetenus(Set $set): ?array
    {
        $retenus = $set->getAttribute('records_detenus');

        if (! is_array($retenus)) {
            return null;
        }

        return array_values(array_filter($retenus, is_string(...)));
    }

    /**
     * Ne reconstruit que si la série modifiée est celle qu'un record désigne.
     *
     * Appelable à chaque enregistrement sans crainte : la question tient en une
     * lecture d'index, et la quasi-totalité des séries ne détiennent rien.
     *
     * La question se pose sous le verrou de l'exercice (#1984), dans la même
     * section que la reconstruction. Posée avant, elle croisait la
     * synchronisation de la série elle-même, partie sur l'ancienne valeur :
     * « rien », parce que la synchronisation n'avait pas encore écrit, puis
     * la synchronisation écrivait l'ancienne valeur, et le record restait.
     */
    public function refreshRecordsHeldBy(Set $set, ?User $user = null): void
    {
        /**
         * Seulement les records que cette série détient vraiment. Reconstruire
         * les trois irait au-delà du changement et remettrait à plat des records
         * que cette série ne touche pas — une série qui bat le 1RM sans battre
         * le poids maximal entraînerait ce dernier avec elle.
         */
        /*
         * Les valeurs de l'enumeration, pas ses instances : `type` est cast, donc
         * une comparaison stricte contre les cles textuelles plus bas ne
         * correspondrait jamais et la reconstruction ne selectionnerait
         * silencieusement rien.
         *
         * Le detour defensif qui etait ici — `instanceof BackedEnum`, sinon
         * `is_string`, sinon chaine vide — supposait que `pluck()` puisse rendre
         * autre chose qu'une instance. Mesure faite : il applique le cast et rend
         * toujours un `PersonalRecordType`. La moitie de l'aiguillage etait donc
         * morte, avec ses quatre mutants, et le `filter()` qui ecartait la chaine
         * vide n'avait rien a ecarter.
         */
        $this->reconstruireSousLeVerrou($set, $user, fn (): array => $this->typesDetenusPar($set));
    }

    /** @return list<string> */
    public function typesDetenusPar(Set $set): array
    {
        return array_values(
            PersonalRecord::query()
                ->where('set_id', $set->id)
                ->get(['type'])
                ->map(fn (PersonalRecord $record): string => $record->type->value)
                ->all()
        );
    }

    /**
     * Reconstruit, pour la série qui s'en va, les records qu'elle détenait.
     *
     * La garde ci-dessus ne sert à rien après une suppression :
     * `personal_records.set_id` est remis à null par la base à l'instant où la
     * ligne part, si bien qu'au moment où `deleted` se déclenche, plus rien
     * n'avoue que la série détenait quoi que ce soit. `Set::deleting` retient
     * donc ce qu'elle détenait (`retenirTypesDetenus()`), hors du verrou.
     *
     * Une synchronisation de la même série peut écrire entre cette lecture et
     * la suppression, et ses records perdent alors leur série (#1984). Sous le
     * verrou, la reconstruction reprend aussi les records de l'exercice restés
     * sans série : aucun ne tient, `reconstruire()` les rattache toujours à
     * une série.
     *
     * @param  list<string>|null  $types  Les records retenus avant la suppression ; null les reconstruit tous.
     */
    public function refreshFor(Set $set, ?User $user = null, ?array $types = null): void
    {
        $this->reconstruireSousLeVerrou(
            $set,
            $user,
            fn (int $idUtilisateur, int $idExercice): ?array => $types === null
                ? null
                : array_values(array_unique([...$types, ...$this->typesSansSerie($idUtilisateur, $idExercice)])),
        );
    }

    /**
     * Les types des records de l'exercice qui ne désignent aucune série.
     *
     * @return list<string>
     */
    private function typesSansSerie(int $idUtilisateur, int $idExercice): array
    {
        return array_values(
            PersonalRecord::query()
                ->where('user_id', $idUtilisateur)
                ->where('exercise_id', $idExercice)
                ->whereNull('set_id')
                ->get(['type'])
                ->map(fn (PersonalRecord $record): string => $record->type->value)
                ->all()
        );
    }

    /**
     * Décide sous le verrou de l'exercice de la série quels records
     * reconstruire, puis les reconstruit dans la même section (#1984).
     *
     * @param  Closure(int, int): (list<string>|null)  $aReconstruire  Reçoit le compte et l'exercice ; rend les records à reconstruire, une liste vide pour aucun, null pour tous.
     */
    private function reconstruireSousLeVerrou(Set $set, ?User $user, Closure $aReconstruire): void
    {
        $set->loadMissing(['workoutLine.workout.user']);
        $idExercice = $set->workoutLine?->exercise_id;
        $user ??= $set->workoutLine?->workout?->user;

        if (! $user instanceof User || $idExercice === null) {
            return;
        }

        $idExercice = (int) $idExercice;

        $this->sousLeVerrou($user->id, $idExercice, function () use ($user, $idExercice, $aReconstruire): void {
            $types = $aReconstruire($user->id, $idExercice);

            if ($types === []) {
                return;
            }

            $this->reconstruire($user, $idExercice, $types);
        });
    }

    /**
     * Confronte la série aux trois records suivis, en une seule lecture de
     * ceux qui tiennent, puis annonce ceux qu'elle a battus.
     *
     * Deux séries du même exercice donnent deux travaux de file, qui
     * tournent en même temps (#1984). Chacun lisait les records, comparait,
     * puis écrivait : le second à écrire posait sa valeur sur une lecture
     * périmée, même plus basse, et le premier record de l'exercice, inséré
     * deux fois, faisait échouer l'un des deux sur la contrainte d'unicité.
     * La lecture, la comparaison et l'écriture se font donc sous le verrou du
     * couple (compte, exercice), et la lecture juste avant de comparer.
     *
     * La série elle-même se relit sous le verrou. Le travail de file la
     * charge à son démarrage, et une correction faite depuis (poids, case
     * décochée, suppression) n'y paraissait pas : la synchronisation écrivait
     * l'ancienne valeur, que plus rien ne venait corriger. Une série qui a
     * disparu, ou qui ne peut plus établir de record, n'écrit rien.
     *
     * Une violation d'unicité ne devrait plus arriver : il faudrait qu'un
     * verrou ait expiré, ou qu'une écriture passe hors de ce service. Elle
     * n'est plus fatale pour autant : `recompute()` repart des séries, et la
     * série est annoncée pour chaque record qu'elle détient désormais.
     *
     * Les annonces partent une fois le verrou rendu : elles ne retiennent
     * pas les autres écritures de l'exercice.
     *
     * @param  \App\Models\User  $user  L'auteur de la série.
     * @param  int  $idExercice  L'exercice concerné.
     * @param  \App\Models\Set  $set  La série, jugée recevable telle que l'appelant l'a chargée.
     */
    private function processUpdates(User $user, int $idExercice, Set $set): void
    {
        /** @var array<string, array{serie: int|null, valeur: float}> $avant */
        $avant = [];

        try {
            $etablis = $this->sousLeVerrou($user->id, $idExercice, function () use ($user, $idExercice, $set, &$avant): array {
                $serie = $set->fresh(['workoutLine']);

                if ($serie === null || $this->shouldSkipSync($serie)) {
                    return [];
                }

                $existingPRs = PersonalRecord::where('user_id', $user->id)
                    ->where('exercise_id', $idExercice)
                    ->get()
                    ->keyBy('type');

                foreach ($existingPRs as $type => $record) {
                    $avant[(string) $type] = ['serie' => $record->set_id, 'valeur' => (float) $record->value];
                }

                return array_values(array_filter([
                    $this->update($user, $idExercice, 'max_weight', (float) $serie->weight, (float) $serie->reps, $serie, $existingPRs->get('max_weight')),
                    $this->update($user, $idExercice, 'max_1rm', $this->calculate1RM((float) $serie->weight, (int) $serie->reps), (float) $serie->weight, $serie, $existingPRs->get('max_1rm')),
                    $this->update($user, $idExercice, 'max_volume_set', (float) $serie->weight * (int) $serie->reps, null, $serie, $existingPRs->get('max_volume_set')),
                ]));
            });
        } catch (UniqueConstraintViolationException) {
            $this->recompute($user, $idExercice);

            $etablis = $this->recordsGagnesPar($set, $user, $idExercice, $avant);
        }

        if ($etablis !== [] && $user->notificationActivee('personal_record')) {
            foreach ($etablis as $record) {
                $user->notify(new PersonalRecordAchieved($record));
            }
        }
    }

    /**
     * Les records que la série détient après une reconstruction, et qu'elle
     * ne détenait pas, ou à moindre valeur, avant sa synchronisation.
     *
     * @param  array<string, array{serie: int|null, valeur: float}>  $avant  Les records lus avant la synchronisation, par type.
     * @return list<PersonalRecord>
     */
    private function recordsGagnesPar(Set $set, User $user, int $idExercice, array $avant): array
    {
        return array_values(PersonalRecord::query()
            ->where('user_id', $user->id)
            ->where('exercise_id', $idExercice)
            ->where('set_id', $set->id)
            ->get()
            ->filter(function (PersonalRecord $record) use ($avant, $set): bool {
                $precedent = $avant[$record->type->value] ?? null;

                return $precedent === null
                    || $precedent['serie'] !== $set->id
                    || (float) $record->value > $precedent['valeur'];
            })
            ->all());
    }

    /**
     * Exécute une section de lecture puis d'écriture des records d'un
     * exercice sous un verrou atomique du couple (compte, exercice) (#1984).
     *
     * Le cache est celui de l'application, partagé par la requête web et les
     * processus de file : c'est entre eux que se croisaient les écritures. Un
     * verrou par exercice laisse travailler en parallèle les autres exercices
     * et les autres comptes. La clé d'unicité du travail de file porte sur la
     * série, et ne pouvait pas le faire : deux séries, deux travaux.
     *
     * Une transaction et `lockForUpdate()` ne suffiraient pas : il n'y a
     * aucune ligne à verrouiller avant le premier record de l'exercice. Le
     * verrou se rend à la fin de la section ; appelée dans une transaction
     * ouverte, une section le rendrait avant la validation, ce que ne fait
     * aujourd'hui aucun de ses appelants.
     *
     * Un verrou resté pris plus de `ATTENTE_DU_VERROU` secondes lève une
     * `LockTimeoutException`, et la section ne s'exécute pas. Ce que la
     * section lève remonte tel quel, verrou rendu.
     *
     * @template TResultat
     *
     * @param  Closure(): TResultat  $section
     * @return TResultat
     */
    private function sousLeVerrou(int $idUtilisateur, int $idExercice, Closure $section): mixed
    {
        /** @var TResultat */
        return Cache::lock("records:{$idUtilisateur}:{$idExercice}", self::VIE_DU_VERROU)
            ->block(self::ATTENTE_DU_VERROU, $section);
    }
}
