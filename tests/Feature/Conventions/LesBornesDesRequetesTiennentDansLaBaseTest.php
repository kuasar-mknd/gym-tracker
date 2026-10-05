<?php

declare(strict_types=1);

use App\Http\Requests\Api\SetStoreRequest;
use App\Http\Requests\Api\SetUpdateRequest;
use App\Http\Requests\HabitStoreRequest;
use App\Http\Requests\HabitUpdateRequest;
use App\Models\Set;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ReglesDesRequetes;

/*
 * Une règle de validation plus large que sa colonne laisse passer une valeur
 * que la base refuse : la requête finit en 500 au lieu d'un refus sur le champ
 * (#1986). La description d'une habitude acceptait mille caractères pour une
 * colonne de 255, le poids d'une série n'avait pas de borne haute pour une
 * colonne decimal(8,2).
 *
 * Cette garde confronte la borne de chaque champ listé à la capacité de la
 * colonne qui le reçoit, lue dans la base de test et non dans le dump : une
 * migration récente n'entre dans le dump que bien plus tard. Un champ recopié
 * ailleurs (les séries d'un modèle deviennent celles d'une séance) se
 * confronte aussi à la colonne d'arrivée.
 */

/**
 * Les champs confrontés : la requête, le champ, et la colonne qui le reçoit.
 *
 * @return list<array{0: class-string<FormRequest>, 1: string, 2: string}>
 */
function bornesBaseInventaire(): array
{
    $champs = [];

    foreach ([HabitStoreRequest::class, HabitUpdateRequest::class] as $requete) {
        $champs[] = [$requete, 'name', 'habits.name'];
        $champs[] = [$requete, 'description', 'habits.description'];
        $champs[] = [$requete, 'goal_times_per_week', 'habits.goal_times_per_week'];
    }

    foreach ([SetStoreRequest::class, SetUpdateRequest::class] as $requete) {
        foreach (['weight', 'reps', 'duration_seconds', 'distance_km'] as $champ) {
            $champs[] = [$requete, $champ, 'sets.'.$champ];
        }
    }

    return $champs;
}

/**
 * La capacité d'une colonne : ses valeurs extrêmes pour un nombre, son nombre
 * de caractères pour un texte. Un `text` compte quatre octets par caractère,
 * le pire cas d'utf8mb4.
 *
 * @return array{genre: 'nombre'|'texte', min: float, max: float}
 */
function bornesBaseCapacite(string $cible): array
{
    [$table, $colonne] = explode('.', $cible, 2);
    $definition = collect(Schema::getColumns($table))->firstWhere('name', $colonne);

    if (! is_array($definition) || ! is_string($definition['type'] ?? null)) {
        throw new LogicException("Colonne {$cible} introuvable dans la base de test.");
    }

    $type = strtolower($definition['type']);
    $nonSigne = str_contains($type, 'unsigned');

    if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)\b/', $type, $entier) === 1) {
        $bits = ['tinyint' => 8, 'smallint' => 16, 'mediumint' => 24, 'int' => 32, 'bigint' => 64][$entier[1]];

        return $nonSigne
            ? ['genre' => 'nombre', 'min' => 0.0, 'max' => 2 ** $bits - 1]
            : ['genre' => 'nombre', 'min' => -(2 ** ($bits - 1)), 'max' => 2 ** ($bits - 1) - 1];
    }

    if (preg_match('/^decimal\((\d+),(\d+)\)/', $type, $decimal) === 1) {
        $max = 10 ** ((int) $decimal[1] - (int) $decimal[2]) - 10 ** -((int) $decimal[2]);

        return ['genre' => 'nombre', 'min' => $nonSigne ? 0.0 : -$max, 'max' => $max];
    }

    if (preg_match('/^(double|float)\b/', $type) === 1) {
        return ['genre' => 'nombre', 'min' => -PHP_FLOAT_MAX, 'max' => PHP_FLOAT_MAX];
    }

    if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $chaine) === 1) {
        return ['genre' => 'texte', 'min' => 0.0, 'max' => (float) $chaine[1]];
    }

    if (preg_match('/^(tiny|medium|long)?text\b/', $type, $texte) === 1) {
        $octets = ['tiny' => 255, '' => 65_535, 'medium' => 16_777_215, 'long' => 4_294_967_295][$texte[1] ?? ''];

        return ['genre' => 'texte', 'min' => 0.0, 'max' => (float) intdiv($octets, 4)];
    }

    throw new LogicException("Type de colonne non confronté : {$cible} ({$type}).");
}

/**
 * Ce qui, dans les règles d'un champ, laisse passer une valeur que la colonne
 * refuserait.
 *
 * @param  list<string|object>  $regles
 * @return list<string>
 */
function bornesBaseFautes(array $regles, string $cible): array
{
    $capacite = bornesBaseCapacite($cible);
    $bornes = ReglesDesRequetes::bornes($regles);
    $fautes = [];

    if ($capacite['genre'] === 'nombre' && ! ReglesDesRequetes::estNumerique($regles)) {
        $fautes[] = "n'est pas validé comme un nombre";
    }

    if ($bornes['max'] === null) {
        $fautes[] = "n'a pas de borne haute ; la colonne s'arrête à {$capacite['max']}";
    } elseif ($bornes['max'] > $capacite['max']) {
        $fautes[] = "accepte jusqu'à {$bornes['max']}, la colonne s'arrête à {$capacite['max']}";
    }

    if ($capacite['genre'] === 'nombre') {
        if ($bornes['min'] === null) {
            $fautes[] = "n'a pas de borne basse ; la colonne commence à {$capacite['min']}";
        } elseif ($bornes['min'] < $capacite['min']) {
            $fautes[] = "accepte dès {$bornes['min']}, la colonne commence à {$capacite['min']}";
        }
    }

    return $fautes;
}

it('borne chaque champ listé sous la capacité de la colonne qui le reçoit', function (): void {
    $fautes = [];

    foreach (bornesBaseInventaire() as [$requete, $champ, $cible]) {
        $regles = ReglesDesRequetes::de($requete)[$champ] ?? null;

        if ($regles === null) {
            $fautes[] = "{$requete} : aucune règle pour {$champ}";

            continue;
        }

        foreach (bornesBaseFautes($regles, $cible) as $faute) {
            $fautes[] = "{$requete} : {$champ} ({$cible}) {$faute}";
        }
    }

    expect($fautes)->toBe([], "règles plus larges que leur colonne :\n  ".implode("\n  ", $fautes));
});

/*
 * La garde voit le défaut d'origine : sans quoi elle ne prouverait rien.
 */
it('refuse les règles d’origine de la description d’habitude et du poids d’une série', function (): void {
    expect(bornesBaseFautes(['nullable', 'string', 'max:1000'], 'habits.description'))->not->toBe([])
        ->and(bornesBaseFautes(['nullable', 'numeric', 'min:0'], 'sets.weight'))->not->toBe([])
        ->and(bornesBaseFautes(['nullable', 'integer'], 'sets.reps'))->toHaveCount(2)
        ->and(bornesBaseFautes(['nullable', 'string', 'max:255'], 'habits.description'))->toBe([]);
});

/*
 * Les records se calculent sur les séries et se rangent dans
 * `personal_records.value` : le volume de la plus lourde série permise (poids
 * × répétitions) et son 1RM estimé (Epley) doivent y tenir, sans quoi le
 * recalcul des records casserait sur une série que la validation a admise.
 */
it('garde le volume et le 1RM de la plus lourde série permise dans la colonne des records', function (): void {
    $capacite = bornesBaseCapacite('personal_records.value');

    expect((float) (Set::POIDS_MAX_KG * Set::REPETITIONS_MAX))->toBeLessThanOrEqual($capacite['max'])
        ->and(Set::POIDS_MAX_KG * (1 + Set::REPETITIONS_MAX / 30))->toBeLessThanOrEqual($capacite['max']);
});
