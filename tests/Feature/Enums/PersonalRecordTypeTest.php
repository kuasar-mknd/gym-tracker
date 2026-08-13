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
 * quatre valeurs héritées ('1RM', 'strength', 'cardio', 'volume') encore
 * présentes dans les bases existantes. Supprimer ou renommer l'un de ces cases
 * ne casse rien à l'écriture : ça casse à la relecture, quand le cast
 * rencontre une ligne historique et lève un ValueError.
 */
describe('PersonalRecordType : valeurs persistées', function (): void {
    it('garde la valeur stockée de chaque type courant', function (): void {
        expect(PersonalRecordType::MaxWeight->value)->toBe('max_weight')
            ->and(PersonalRecordType::Max1RM->value)->toBe('max_1rm')
            ->and(PersonalRecordType::MaxVolumeSet->value)->toBe('max_volume_set');
    });

    it('garde les valeurs héritées encore présentes en base', function (): void {
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
            ->and($reloaded->toArray()['type'])->toBe($type->value);
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
            ->toBe("Félicitations ! Tu as battu ton record de {$expectedLabel} sur l'exercice Développé couché avec 102.50kg.")
            ->and($payload['exercise_id'])->toBe($exercise->id);
    })->with([
        'max_weight' => [PersonalRecordType::MaxWeight, 'Poids Maximum'],
        'max_1rm' => [PersonalRecordType::Max1RM, '1RM Estimé'],
        'max_volume_set' => [PersonalRecordType::MaxVolumeSet, 'Volume par Série'],
        // Les valeurs héritées retombent sur la branche par défaut du match.
        'strength' => [PersonalRecordType::Strength, 'Record Personnel'],
    ]);
});
