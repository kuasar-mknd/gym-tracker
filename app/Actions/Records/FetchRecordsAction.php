<?php

declare(strict_types=1);

namespace App\Actions\Records;

use App\Enums\PersonalRecordType;
use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Stats\ClesDeStats;
use Illuminate\Support\Facades\Cache;

/**
 * Ce que la page Records affiche : pour chaque exercice du compte, le poids le
 * plus lourd, le 1RM estimé et la meilleure série en volume, chacun avec le
 * jour où il a été établi.
 *
 * Les records ne sont lus que du compte, et jamais par le modèle : une ligne
 * d'un type que l'enum ne connaît plus le ferait lever au cast
 * (`.ai/rules/database.md`), et seuls les types suivis sont gardés, comparés
 * ici octet par octet.
 *
 * La lecture est bornée : les `LIMITE` exercices dont un record est le plus
 * récent, puis les records de ceux-là, puis leurs noms par clef. Trois lectures
 * à froid, quel que soit le nombre d'exercices ; la seule lecture des noms
 * quand l'entrée de cache est là, pour qu'un exercice renommé ou supprimé ne
 * reste pas dit comme avant. L'entrée vit sous la version des séances et
 * celle des records (`ClesDeStats::records`), que `PersonalRecordService`
 * renouvelle à chaque écriture de record : un record écrit par la file après la requête qui a validé la série
 * ne reste pas caché derrière une entrée d'avant.
 *
 * @phpstan-type Mesure array{valeur: float, date: string|null}
 * @phpstan-type Records array{
 *     exercice_id: int,
 *     date: string|null,
 *     poids_max: array{valeur: float, repetitions: int, date: string|null}|null,
 *     un_rm: Mesure|null,
 *     volume_serie: Mesure|null
 * }
 * @phpstan-type Exercice array{
 *     exercice_id: int,
 *     nom: string,
 *     date: string|null,
 *     poids_max: array{valeur: float, repetitions: int, date: string|null}|null,
 *     un_rm: Mesure|null,
 *     volume_serie: Mesure|null
 * }
 */
final class FetchRecordsAction
{
    /**
     * Combien d'exercices la page porte au plus.
     */
    public const int LIMITE = 60;

    /**
     * @return array{records: list<Exercice>, tronque: bool}
     */
    public function execute(User $user): array
    {
        $lus = Cache::remember(
            ClesDeStats::records($user, 'personal_records'),
            now()->addMinutes(30),
            fn (): array => $this->lire($user),
        );

        if ($lus['records'] === []) {
            return ['records' => [], 'tronque' => false];
        }

        $noms = Exercise::query()
            ->toBase()
            ->whereIn('id', array_column($lus['records'], 'exercice_id'))
            ->pluck('name', 'id');

        $records = [];

        foreach ($lus['records'] as $record) {
            $nom = $noms->get($record['exercice_id']);

            if (is_string($nom)) {
                $records[] = [
                    'exercice_id' => $record['exercice_id'],
                    'nom' => $nom,
                    'date' => $record['date'],
                    'poids_max' => $record['poids_max'],
                    'un_rm' => $record['un_rm'],
                    'volume_serie' => $record['volume_serie'],
                ];
            }
        }

        return ['records' => $records, 'tronque' => $lus['tronque']];
    }

    /**
     * @return array{records: list<Records>, tronque: bool}
     */
    private function lire(User $user): array
    {
        $identifiants = PersonalRecord::query()
            ->toBase()
            ->where('user_id', $user->id)
            ->whereRaw('cast(type as binary) in (?, ?, ?)', PersonalRecordType::SUIVIS)
            ->groupBy('exercise_id')
            ->orderByRaw('max(achieved_at) desc')
            ->orderByDesc('exercise_id')
            ->limit(self::LIMITE + 1)
            ->pluck('exercise_id')
            ->map(static fn (mixed $identifiant): int => is_numeric($identifiant) ? (int) $identifiant : 0);

        $tronque = $identifiants->count() > self::LIMITE;
        $identifiants = $identifiants->take(self::LIMITE)->values();

        if ($identifiants->isEmpty()) {
            return ['records' => [], 'tronque' => false];
        }

        $lignes = PersonalRecord::query()
            ->toBase()
            ->where('user_id', $user->id)
            ->whereIn('exercise_id', $identifiants->all())
            ->whereRaw('cast(type as binary) in (?, ?, ?)', PersonalRecordType::SUIVIS)
            ->get(['exercise_id', 'type', 'value', 'secondary_value', 'achieved_at']);

        $parExercice = $lignes->groupBy(static fn (\stdClass $ligne): int => is_numeric($ligne->exercise_id) ? (int) $ligne->exercise_id : 0);
        $records = [];

        foreach ($identifiants as $identifiant) {
            $records[] = $this->exercice($identifiant, $parExercice->get($identifiant)?->all() ?? []);
        }

        return ['records' => $records, 'tronque' => $tronque];
    }

    /**
     * @param  array<int, \stdClass>  $lignes  Les records bruts de l'exercice.
     * @return Records
     */
    private function exercice(int $identifiant, array $lignes): array
    {
        $poidsMax = null;
        $unRm = null;
        $volumeSerie = null;
        $dates = [];

        foreach ($lignes as $ligne) {
            if (! is_numeric($ligne->value)) {
                continue;
            }

            $valeur = (float) $ligne->value;
            $date = is_string($ligne->achieved_at) ? substr($ligne->achieved_at, 0, 10) : null;

            if ($date !== null) {
                $dates[] = $date;
            }

            switch ($ligne->type) {
                case PersonalRecordType::MaxWeight->value:
                    $poidsMax = [
                        'valeur' => $valeur,
                        'repetitions' => is_numeric($ligne->secondary_value) ? (int) $ligne->secondary_value : 0,
                        'date' => $date,
                    ];
                    break;
                case PersonalRecordType::Max1RM->value:
                    $unRm = ['valeur' => $valeur, 'date' => $date];
                    break;
                case PersonalRecordType::MaxVolumeSet->value:
                    $volumeSerie = ['valeur' => $valeur, 'date' => $date];
                    break;
            }
        }

        return [
            'exercice_id' => $identifiant,
            'date' => $dates === [] ? null : max($dates),
            'poids_max' => $poidsMax,
            'un_rm' => $unRm,
            'volume_serie' => $volumeSerie,
        ];
    }
}
