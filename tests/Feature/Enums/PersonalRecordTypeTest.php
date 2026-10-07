<?php

declare(strict_types=1);

use App\Enums\PersonalRecordType;
use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Notifications\PersonalRecordAchieved;
use Illuminate\Support\Facades\DB;

/**
 * personal_records.type est un varchar sans contrainte, et l'enum traîne
 * quatre valeurs héritées ('1RM', 'strength', 'cardio', 'volume'). Plus aucun
 * code de l'application ne les écrit ; la fabrique de tests écrivait encore
 * 'strength' et 'cardio' jusqu'à #1811. On ne sait pas si la production en
 * porte : `app:verify-data-coherence` les compte chaque nuit.
 * Supprimer ou renommer l'un de ces cas ne casse rien à l'écriture : ça casse
 * à la relecture, quand le cast rencontre une ligne historique et lève une
 * ValueError.
 */
describe('PersonalRecordType : valeurs persistées', function (): void {
    it('garde la valeur stockée de chaque type courant', function (): void {
        expect(PersonalRecordType::MaxWeight->value)->toBe('max_weight')
            ->and(PersonalRecordType::Max1RM->value)->toBe('max_1rm')
            ->and(PersonalRecordType::MaxVolumeSet->value)->toBe('max_volume_set');
    });

    it('garde les valeurs héritées tant que la base n’a pas été vérifiée', function (): void {
        expect(PersonalRecordType::OneRM->value)->toBe('1RM')
            ->and(PersonalRecordType::Strength->value)->toBe('strength')
            ->and(PersonalRecordType::Cardio->value)->toBe('cardio')
            ->and(PersonalRecordType::Volume->value)->toBe('volume');
    });

    it('ne déclare que ces sept types, dans cet ordre', function (): void {
        expect(array_map(
            fn (PersonalRecordType $type): string => $type->value,
            PersonalRecordType::cases(),
        ))->toBe(['max_weight', 'max_1rm', 'max_volume_set', '1RM', 'strength', 'cardio', 'volume']);
    });
});

/**
 * Les types que l'application tient ne s'écrivent qu'à un endroit.
 *
 * Le service les tenait dans une constante privée, et le contrôle de
 * cohérence en a besoin pour compter les autres : deux listes finiraient par
 * diverger. Elles se lisent désormais sur l'enum.
 */
describe('PersonalRecordType : les types suivis', function (): void {
    it('nomme exactement les trois types que l’application écrit, dans cet ordre', function (): void {
        expect(PersonalRecordType::SUIVIS)->toBe(['max_weight', 'max_1rm', 'max_volume_set']);
    });

    /*
     * La fabrique tirait au sort 'strength' ou 'cardio' : des types
     * d'exercice, que l'application n'écrit pas comme records. Tout test
     * qui ne précisait pas le type remplissait la base de valeurs héritées, et
     * un tirage ne prouve rien.
     */
    it('fait écrire par la fabrique un type suivi, toujours le même', function (): void {
        PersonalRecord::factory()->count(6)->create();

        $ecrits = DB::table('personal_records')->distinct()->pluck('type')->all();

        expect($ecrits)->toBe([PersonalRecordType::MaxWeight->value])
            ->and(PersonalRecordType::SUIVIS)->toContain(PersonalRecordType::MaxWeight->value);
    });
});

describe('PersonalRecordType : aller-retour par le cast du modèle', function (): void {
    it('écrit la valeur de backing et relit une instance d\'enum', function (PersonalRecordType $type): void {
        $user = User::factory()->create();
        $record = PersonalRecord::factory()->create([
            'user_id' => $user->id,
            'type' => $type,
        ]);

        expect(DB::table('personal_records')->where('id', $record->id)->value('type'))
            ->toBe($type->value);

        $reloaded = PersonalRecord::findOrFail($record->id);

        expect($reloaded->type)->toBe($type)
            ->and($reloaded->toArray())->toHaveKey('type', $type->value);
    })->with([
        'max_weight' => [PersonalRecordType::MaxWeight],
        'max_1rm' => [PersonalRecordType::Max1RM],
        'max_volume_set' => [PersonalRecordType::MaxVolumeSet],
        '1RM' => [PersonalRecordType::OneRM],
        'strength' => [PersonalRecordType::Strength],
        'cardio' => [PersonalRecordType::Cardio],
        'volume' => [PersonalRecordType::Volume],
    ]);
});

/**
 * La notification de record lit l'enum pour construire le message poussé sur
 * le téléphone. C'est le seul endroit qui donne un libellé lisible à ces
 * types : il est écrit en toutes lettres dans PersonalRecordAchieved.
 */
describe('PersonalRecordType nomme le record dans la notification', function (): void {
    it('écrit le libellé long du type dans le message', function (PersonalRecordType $type, string $expectedLabel): void {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Développé couché']);
        $record = PersonalRecord::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'type' => $type,
            'value' => 102.5,
        ]);

        $payload = new PersonalRecordAchieved(PersonalRecord::with('exercise')->findOrFail($record->id))
            ->toArray($user);

        expect($payload['message'])
            ->toBe("Félicitations ! Tu as battu ton record de {$expectedLabel} sur l'exercice Développé couché avec 102,5\u{00A0}kg.")
            ->and($payload['exercise_id'])->toBe($exercise->id);
    })->with([
        'max_weight' => [PersonalRecordType::MaxWeight, 'poids maximum'],
        'max_1rm' => [PersonalRecordType::Max1RM, '1RM estimé'],
        'max_volume_set' => [PersonalRecordType::MaxVolumeSet, 'volume par série'],
        // Les valeurs héritées retombent sur la branche par défaut du match.
        'strength' => [PersonalRecordType::Strength, 'record personnel'],
    ]);
});
