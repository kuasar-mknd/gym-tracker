<?php

declare(strict_types=1);

use App\Enums\PersonalRecordType;
use App\Models\User;
use App\Services\PersonalRecordService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Retire les records d'un type que l'application ne tient plus, et refait
 * depuis ses séries chaque exercice qui en portait un, sans rien annoncer
 * (#1811).
 *
 * Plus aucun code n'écrit d'autre type que les trois suivis
 * (`PersonalRecordType::SUIVIS`). L'enum garde quatre cas hérités ('1RM',
 * 'strength', 'cardio', 'volume') faute de savoir si la production en porte
 * encore : un cas retiré alors qu'une ligne le porte fait lever une
 * `ValueError` à chaque relecture par le modèle (le tableau de bord, la page
 * de séance, la synchronisation d'une série, la reconstruction des records de
 * l'exercice). Une valeur que l'enum ne connaît pas lève déjà aujourd'hui, et
 * 'MAX_WEIGHT' ou 'max_weight ' en font partie : la colonne est en
 * utf8mb4_unicode_ci, insensible à la casse et PAD SPACE, et ses requêtes les
 * confondent avec 'max_weight', alors que le cast les refuse. Les lignes se
 * choisissent donc octet par octet (`CAST(type AS BINARY)`), comme le contrôle
 * « type des records » de `app:verify-data-coherence`, qui les signale chaque
 * nuit.
 *
 * Chaque couple (compte, exercice) qui porte une telle ligne passe par
 * `PersonalRecordService::retirerLesTypesNonSuivis()` : sous le verrou des
 * records de l'exercice, les lignes partent en base, sans le modèle, puis
 * l'exercice se reconstruit comme par `reparer()`, sans annonce. Un record
 * suivi que la reconstruction recrée ou remonte n'est pas un exploit : il
 * remplace une valeur que le modèle ne relisait pas, ou qui s'écartait de ses
 * séries. Un 'cardio' n'a pas d'équivalent suivi et disparaît, comme toute
 * valeur que plus aucune série éligible ne soutient. Les types hérités se
 * relisaient, eux : le tableau de bord les montrait parmi les derniers
 * records, et ils en disparaissent, les records suivis refaits à leur place.
 * Les exercices qui ne portent aucune telle ligne ne sont pas touchés.
 *
 * Une ligne dont le compte a disparu (la clé étrangère l'emporte avec lui ;
 * seule une base restaurée clés coupées en garde) part aussi, en base, sans
 * rien reconstruire pour personne.
 *
 * Le journal dit, sans valeur ni compte, combien de records chaque type a
 * perdus, leurs identifiants et le nombre d'exercices refaits ; il n'écrit
 * rien quand il n'y avait rien à retirer. La sauvegarde de la nuit garde les
 * lignes retirées.
 *
 * Rejouable : un second passage ne trouve plus rien à retirer.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_records')) {
            return;
        }

        /** @var array<string, int> $parType */
        $parType = [];

        /** @var list<int> $records */
        $records = [];

        /** @var array<string, array{0: int, 1: int}> $couples */
        $couples = [];

        $lignes = DB::table('personal_records')
            ->whereNotIn(DB::raw('CAST(type AS BINARY)'), PersonalRecordType::SUIVIS)
            ->selectRaw('id, user_id, exercise_id, CAST(type AS BINARY) as type_brut')
            ->orderBy('id')
            ->get();

        foreach ($lignes as $ligne) {
            $champs = get_object_vars($ligne);
            $type = is_scalar($champs['type_brut'] ?? null) ? (string) $champs['type_brut'] : '';
            $idUtilisateur = self::entier($champs['user_id'] ?? null);
            $idExercice = self::entier($champs['exercise_id'] ?? null);

            $parType[$type] = ($parType[$type] ?? 0) + 1;
            $records[] = self::entier($champs['id'] ?? null);
            $couples["{$idUtilisateur}:{$idExercice}"] = [$idUtilisateur, $idExercice];
        }

        if ($records === []) {
            return;
        }

        ksort($parType, SORT_STRING);

        $service = app(PersonalRecordService::class);
        $comptes = User::query()->whereKey(array_values(array_unique(array_column($couples, 0))))->get()->keyBy('id');
        $refaits = 0;

        foreach ($couples as [$idUtilisateur, $idExercice]) {
            $compte = $comptes->get($idUtilisateur);

            if ($compte instanceof User) {
                $service->retirerLesTypesNonSuivis($compte, $idExercice);
                $refaits++;

                continue;
            }

            DB::table('personal_records')
                ->where('user_id', $idUtilisateur)
                ->where('exercise_id', $idExercice)
                ->whereNotIn(DB::raw('CAST(type AS BINARY)'), PersonalRecordType::SUIVIS)
                ->delete();
        }

        Log::info('Records d’un type que l’application ne tient plus retirés, leurs exercices refaits depuis leurs séries (#1811)', [
            'types' => $parType,
            'records' => $records,
            'exercices_refaits' => $refaits,
            'exercices_sans_compte' => count($couples) - $refaits,
        ]);
    }

    /**
     * Volontairement vide : remettre ces lignes rendrait au modèle des types
     * qu'il ne relit pas, ou que l'enum s'apprête à ne plus connaître, et la
     * migration n'a gardé ni leur valeur ni leur série ; la sauvegarde de la
     * nuit les garde.
     */
    public function down(): void
    {
    }

    /**
     * Un identifiant lu dans une ligne brute.
     */
    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }
};
