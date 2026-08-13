<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * goals.type est une colonne ENUM MySQL : la valeur de backing n'est pas
 * seulement affichée, elle doit exister côté base. Renommer un case sans
 * migration fait échouer l'INSERT en production ; en ajouter un sans migration
 * aussi. Ces tests verrouillent les deux côtés du contrat.
 */
describe('GoalType : valeurs persistées', function (): void {
    it('garde la valeur stockée de chaque type', function (): void {
        expect(GoalType::Weight->value)->toBe('weight')
            ->and(GoalType::Volume->value)->toBe('volume')
            ->and(GoalType::Frequency->value)->toBe('frequency')
            ->and(GoalType::Measurement->value)->toBe('measurement');
    });

    it('ne déclare que ces quatre types, dans cet ordre', function (): void {
        expect(array_map(
            fn (GoalType $type): string => $type->value,
            GoalType::cases(),
        ))->toBe(['weight', 'volume', 'frequency', 'measurement']);
    });

    it('correspond exactement à la définition ENUM de la colonne goals.type', function (): void {
        /** @var object{Type: string} $column */
        $column = DB::selectOne("SHOW COLUMNS FROM goals WHERE Field = 'type'");

        preg_match_all("/'([^']+)'/", $column->Type, $matches);

        $inDatabase = $matches[1];
        sort($inDatabase);

        $inPhp = array_map(fn (GoalType $type): string => $type->value, GoalType::cases());
        sort($inPhp);

        expect($inDatabase)->toBe($inPhp);
    });
});

describe('GoalType : aller-retour par le cast du modèle', function (): void {
    it('écrit la valeur de backing et relit une instance d\'enum', function (GoalType $type): void {
        $user = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'type' => $type,
            'measurement_type' => $type === GoalType::Measurement ? 'waist' : null,
        ]);

        expect(DB::table('goals')->where('id', $goal->id)->value('type'))
            ->toBe($type->value);

        $reloaded = Goal::findOrFail($goal->id);

        expect($reloaded->type)->toBe($type)
            ->and($reloaded->toArray()['type'])->toBe($type->value);
    })->with([
        'weight' => [GoalType::Weight],
        'volume' => [GoalType::Volume],
        'frequency' => [GoalType::Frequency],
        'measurement' => [GoalType::Measurement],
    ]);
});

/**
 * Goal::getUnitAttribute() est un match sans branche par défaut sur GoalType :
 * ajouter un case sans le compléter fait exploser la page Objectifs avec un
 * UnhandledMatchError. L'unité est aussi ce qui s'affiche derrière chaque
 * chiffre de la carte d'objectif.
 */
describe('GoalType pilote l\'unité affichée sur l\'objectif', function (): void {
    it('donne l\'unité attendue pour chaque type', function (): void {
        $user = User::factory()->create();

        $unitFor = function (GoalType $type, ?string $measurementType = null) use ($user): string {
            /** @var Goal $goal */
            $goal = Goal::factory()->create([
                'user_id' => $user->id,
                'type' => $type,
                'measurement_type' => $measurementType,
            ]);

            return Goal::findOrFail($goal->id)->unit;
        };

        expect($unitFor(GoalType::Weight))->toBe('kg')
            ->and($unitFor(GoalType::Volume))->toBe('kg')
            ->and($unitFor(GoalType::Frequency))->toBe('séances')
            ->and($unitFor(GoalType::Measurement, 'body_fat'))->toBe('%')
            ->and($unitFor(GoalType::Measurement, 'waist'))->toBe('cm');
    });
});
