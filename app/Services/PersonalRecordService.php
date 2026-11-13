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
        if ($pr !== null && round($value, 2) <= round((float) $pr->value, 2)) {
            return null;
        }

        $pr ??= new PersonalRecord(['user_id' => $user->id, 'exercise_id' => $idExercice, 'type' => $type]);
        $pr->fill(['value' => $value, 'secondary_value' => $secondary, 'workout_id' => $set->workoutLine->workout_id, 'set_id' => $set->id, 'achieved_at' => $set->workoutLine->workout->debutALHeureMurale()])->save();

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
            select sets.id, sets.weight, sets.reps, workouts.started_at as obtenu,
                   workout_lines.workout_id,
                   row_number() over (order by sets.weight desc, sets.id asc) as rang_max_weight,
                   row_number() over (order by (case when sets.reps <= 1 then sets.weight else sets.weight * (1 + sets.reps / 30) end) desc, sets.id asc) as rang_max_1rm,
                   row_number() over (order by sets.weight * sets.reps desc, sets.id asc) as rang_max_volume_set
              from sets
              join workout_lines on workout_lines.id = sets.workout_line_id
              join workouts on workouts.id = workout_lines.workout_id
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
     * une ligne d'un autre type n'est ni recalculée ni supprimée : le contrôle
     * de cohérence la signale, et `retirerLesTypesNonSuivis()` la retire.
     *
     * Sous le verrou du couple (compte, exercice) (#1984) : la requête web qui
     * corrige une série reconstruit pendant qu'un processus de file
     * synchronise une autre série du même exercice, et chacun écrivait sur
     * une lecture que l'autre venait de rendre fausse.
     *
     * Annonce, une fois le verrou rendu, le record que la reconstruction donne
     * à une série dont la synchronisation n'a pas écrit (#2185, `reconstruire()`).
     *
     * @param  list<string>|null  $types  Limite la reconstruction à ces records.
     *                                    Null les prend tous, ce qui est juste quand une série a disparu et
     *                                    que plus rien ne dit quels records elle détenait.
     */
    public function recompute(User $user, int $idExercice, ?array $types = null): void
    {
        $this->annoncer($user, $this->reconstruireLExercice($user, $idExercice, $types));
    }

    /**
     * Reconstruit les records d'un exercice pour réparer des données, sans
     * rien annoncer : le contrôle de cohérence (`--repair`) et la migration
     * qui remonte les records restés sous leur meilleure série
     * (`2026_11_05_152528_remonter_les_records_restes_sous_leur_meilleure_serie`).
     *
     * La réparation reprend des records qu'une écriture d'avant les correctifs
     * a laissés faux : un record resté sous la meilleure série peut désigner une
     * série que sa synchronisation a déjà annoncée, puis qu'une écriture plus
     * lente a écrasée (#1984). Le remonter n'est pas un exploit à annoncer de
     * nouveau ; la reconstruction des écritures de l'application, elle, ne
     * remonte un record que vers une série que sa synchronisation n'a pas encore
     * écrite (#2185).
     */
    public function reparer(User $user, int $idExercice): void
    {
        $this->reconstruireLExercice($user, $idExercice, null);
    }

    /**
     * Retire les records de l'exercice dont le type n'est pas l'un des types
     * suivis, puis reconstruit l'exercice depuis ses séries, sans rien
     * annoncer, comme `reparer()` (#1811) : la migration
     * `2026_11_06_161554_retirer_les_records_d_un_type_non_suivi` et
     * `app:verify-data-coherence --repair`.
     *
     * Ces lignes portent un type que plus aucun code n'écrit : les quatre types
     * hérités que l'enum garde encore, ou une valeur qu'il ne connaît pas. Le
     * type se compare octet par octet (`CAST(type AS BINARY)`), comme
     * `PersonalRecordType::from()` : la colonne est en utf8mb4_unicode_ci,
     * insensible à la casse et PAD SPACE, et un `whereNotIn('type', …)`
     * garderait 'MAX_WEIGHT' et 'max_weight ', que le modèle ne relit pas.
     * La suppression se fait en base, sans passer par le modèle, qui lèverait
     * au cast (`ValueError`) sur ces mêmes valeurs.
     *
     * Le retrait précède la reconstruction, sous le même verrou : celle-ci
     * relit les records de l'exercice par le modèle, et l'index unique, qui
     * suit la collation, refuserait le type suivi qu'elle recrée à côté de la
     * variante qui l'imite. Une valeur que plus aucune série ne soutient
     * disparaît ; les autres se refont depuis la meilleure série éligible.
     *
     * `reparer()` ne retire rien : la migration de la 1.7.0 qui l'appelle
     * garde le comportement avec lequel elle a été écrite.
     *
     * @return int Le nombre de records retirés.
     */
    public function retirerLesTypesNonSuivis(User $user, int $idExercice): int
    {
        return $this->sousLeVerrou($user->id, $idExercice, function () use ($user, $idExercice): int {
            $retires = DB::table('personal_records')
                ->where('user_id', $user->id)
                ->where('exercise_id', $idExercice)
                ->whereNotIn(DB::raw('CAST(type AS BINARY)'), PersonalRecordType::SUIVIS)
                ->delete();

            $this->reconstruire($user, $idExercice, null);

            return $retires;
        });
    }

    /**
     * Le corps de `recompute()`, sous le verrou de l'exercice, sans annoncer :
     * rend les annonces dues, à faire une fois le verrou rendu.
     *
     * @param  list<string>|null  $types  Limite la reconstruction à ces records ; null les prend tous.
     * @return array<int, array{record: PersonalRecord, avant: float|null}>
     */
    private function reconstruireLExercice(User $user, int $idExercice, ?array $types): array
    {
        return $this->sousLeVerrou($user->id, $idExercice, fn (): array => $this->reconstruire($user, $idExercice, $types));
    }

    /**
     * Le corps de `recompute()`, sans le verrou : l'appelant le tient déjà.
     *
     * Le verrou n'est pas réentrant. `refreshRecordsHeldBy()` et `refreshFor()`
     * décident de ce qu'il faut reconstruire sous le verrou, puis
     * reconstruisent dans la même section : passer par `recompute()`
     * attendrait leur propre verrou jusqu'au délai.
     *
     * Rend les records qu'elle donne à une série qui ne les tenait pas,
     * au-dessus de la valeur qu'ils portaient, ou qu'elle crée (#2185). Une
     * série qui compte, et dont la synchronisation a écrit la valeur qu'elle
     * porte, laisse le record au moins à cette valeur, et une reconstruction,
     * qui prend la meilleure des séries qui comptent, ne le descend jamais sous
     * elle : une telle série n'a donc pas été synchronisée depuis sa dernière
     * écriture. Sa synchronisation, que cette écriture a mise en file, ne
     * trouvera plus rien à battre, et personne n'annonçait ce record. L'appelant
     * l'annonce pour elle, une fois le verrou rendu, avec la valeur que le
     * record portait avant, comme sa synchronisation l'aurait fait ; l'annonce
     * se juge à son départ comme les autres
     * (`PersonalRecordAchieved::shouldSend()`). Le record que la série tenait
     * déjà, même remonté par sa correction, et celui qui ne dépasse pas sa
     * valeur d'avant ne sont pas rendus.
     *
     * Les records d'avant les correctifs qui ne tenaient pas cet invariant,
     * restés sous leur meilleure série (#1984), ont été repris une fois, sans
     * annonce, par la migration que nomme `reparer()` ; sous le verrou, les
     * écritures de l'application le tiennent.
     *
     * @param  list<string>|null  $types  Limite la reconstruction à ces records ; null les prend tous.
     * @return array<int, array{record: PersonalRecord, avant: float|null}> Par identifiant du record.
     */
    private function reconstruire(User $user, int $idExercice, ?array $types): array
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

        /** @var array<int, array{record: PersonalRecord, avant: float|null}> $aAnnoncer */
        $aAnnoncer = [];

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

            // Ce que le record portait avant : sa série et sa valeur, lues
            // avant de le réécrire ; null quand la reconstruction le crée.
            $avant = $record === null ? null : ['serie' => $record->set_id, 'valeur' => (float) $record->value];

            $record ??= new PersonalRecord(['user_id' => $user->id, 'exercise_id' => $idExercice, 'type' => $type]);

            /**
             * Une correction n'est pas un exploit : le record que la série
             * tenait déjà, même remonté parce qu'on l'a corrigée, ne s'annonce
             * pas de nouveau — annoncer un record personnel à quelqu'un qui
             * vient de rattraper une faute de frappe serait pire que de se
             * taire.
             *
             * La ligne se réécrit en place : une annonce de la série encore en
             * file la relira à son départ, et ne partira pas si elle ne dépasse
             * plus la valeur d'avant, ou si une autre série la tient plus haut
             * que ce que la série y avait écrit (#2155).
             */
            $record->fill([
                'value' => $valeur,
                'secondary_value' => $secondaire,
                'workout_id' => $meilleure['seance'],
                'set_id' => $meilleure['id'],
                'achieved_at' => $meilleure['obtenu'],
            ])->save();

            // Donné à une série qui ne le tenait pas, au-dessus de ce qu'il
            // portait, ou créé : sa synchronisation n'a pas écrit, et ne
            // l'annoncera pas (#2185).
            if ($avant === null || ($avant['serie'] !== $meilleure['id'] && round($valeur, 2) > round($avant['valeur'], 2))) {
                $aAnnoncer[$record->id] = ['record' => $record, 'avant' => $avant['valeur'] ?? null];
            }
        }

        if ($aSupprimer !== []) {
            PersonalRecord::query()->whereKey($aSupprimer)->delete();
        }

        return $aAnnoncer;
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
     * @return array<string, array{id: int, poids: float, repetitions: int, seance: int, obtenu: string}>
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

                $par[$type] = [
                    'id' => self::entier($champs['id'] ?? null),
                    'poids' => self::flottant($champs['weight'] ?? null),
                    'repetitions' => self::entier($champs['reps'] ?? null),
                    'seance' => self::entier($champs['workout_id'] ?? null),
                    'obtenu' => self::texte($champs['obtenu'] ?? null),
                ];
            }
        }

        return $par;
    }

    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    private static function texte(mixed $valeur): string
    {
        return is_scalar($valeur) ? (string) $valeur : '';
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
     * Annonce ensuite, verrou rendu, le record que la reconstruction donne à
     * une série dont la synchronisation n'a pas écrit (#2185, `reconstruire()`).
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

        $annonces = $this->sousLeVerrou($user->id, $idExercice, function () use ($user, $idExercice, $aReconstruire): array {
            $types = $aReconstruire($user->id, $idExercice);

            if ($types === []) {
                return [];
            }

            return $this->reconstruire($user, $idExercice, $types);
        });

        $this->annoncer($user, $annonces);
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
     * n'est plus fatale pour autant : la reconstruction repart des séries, et
     * la série est annoncée pour chaque record qu'elle détient désormais, une
     * seule fois quand la reconstruction l'annonce déjà (#2185).
     *
     * Les annonces partent une fois le verrou rendu : elles ne retiennent
     * pas les autres écritures de l'exercice. Chacune retient la valeur que
     * son record portait avant la série, lue sous le verrou avant toute
     * écriture, la série qui l'a établi et la valeur qu'elle y a écrite : en
     * file, elle juge le record tel que la file le relit à son départ, et ne
     * part pas s'il ne reste plus à annoncer (#2155,
     * `PersonalRecordAchieved::shouldSend()`).
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

            $annonces = self::annoncesDe($etablis, $avant);
        } catch (UniqueConstraintViolationException) {
            // Ce que la reconstruction annonce d'abord ; ce que la série a
            // gagné ensuite, sans doubler un record déjà annoncé.
            $annonces = $this->reconstruireLExercice($user, $idExercice, null)
                + self::annoncesDe($this->recordsGagnesPar($set, $user, $idExercice, $avant), $avant);
        }

        $this->annoncer($user, $annonces);
    }

    /**
     * Les annonces des records que la série vient d'établir, chacune avec la
     * valeur que son record portait avant elle.
     *
     * @param  list<PersonalRecord>  $records
     * @param  array<string, array{serie: int|null, valeur: float}>  $avant  Les records lus avant la synchronisation, par type.
     * @return array<int, array{record: PersonalRecord, avant: float|null}> Par identifiant du record.
     */
    private static function annoncesDe(array $records, array $avant): array
    {
        $annonces = [];

        foreach ($records as $record) {
            $annonces[$record->id] = ['record' => $record, 'avant' => $avant[$record->type->value]['valeur'] ?? null];
        }

        return $annonces;
    }

    /**
     * Annonce chaque record, une fois le verrou rendu : les annonces ne
     * retiennent pas les autres écritures de l'exercice. Rien ne part quand
     * la personne a coupé l'annonce de ses records.
     *
     * La préférence ne se lit que s'il y a à annoncer : la plupart des
     * synchronisations et des reconstructions n'établissent rien.
     *
     * @param  array<int, array{record: PersonalRecord, avant: float|null}>  $annonces
     */
    private function annoncer(User $user, array $annonces): void
    {
        if ($annonces === [] || ! $user->notificationActivee('personal_record')) {
            return;
        }

        foreach ($annonces as $annonce) {
            $user->notify(new PersonalRecordAchieved($annonce['record'], $annonce['avant']));
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
