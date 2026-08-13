<?php

declare(strict_types=1);

use App\Enums\ExerciseCategory;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * exercises.category est un varchar : rien dans la base ne contraint la valeur.
 * Renommer une valeur de backing ici est donc une migration de données
 * silencieuse — les lignes déjà écrites gardent l'ancienne chaîne et le cast
 * lève un ValueError à l'hydratation, loin du commit fautif. La migration
 * 2026_03_25_092024_fix_exercises_abdos_category en est la trace.
 */
describe('ExerciseCategory : valeurs persistées', function (): void {
    it('garde la valeur stockée de chaque catégorie', function (): void {
        expect(ExerciseCategory::Pectoraux->value)->toBe('Pectoraux')
            ->and(ExerciseCategory::Epaules->value)->toBe('Épaules')
            ->and(ExerciseCategory::Dos->value)->toBe('Dos')
            ->and(ExerciseCategory::Jambes->value)->toBe('Jambes')
            ->and(ExerciseCategory::Bras->value)->toBe('Bras')
            ->and(ExerciseCategory::Abdominaux->value)->toBe('Abdominaux')
            ->and(ExerciseCategory::Cardio->value)->toBe('Cardio');
    });

    it('ne déclare que ces sept catégories, dans cet ordre', function (): void {
        expect(array_map(
            fn (ExerciseCategory $category): string => $category->value,
            ExerciseCategory::cases(),
        ))->toBe(['Pectoraux', 'Épaules', 'Dos', 'Jambes', 'Bras', 'Abdominaux', 'Cardio']);
    });

    it('ne reconnaît plus la valeur héritée « Abdos »', function (): void {
        expect(ExerciseCategory::tryFrom('Abdos'))->toBeNull()
            ->and(ExerciseCategory::tryFrom('Abdominaux'))->toBe(ExerciseCategory::Abdominaux);
    });
});

describe('ExerciseCategory : aller-retour par le cast du modèle', function (): void {
    it('écrit la valeur de backing et relit une instance d\'enum', function (ExerciseCategory $category): void {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'category' => $category,
        ]);

        // La colonne porte la valeur de backing, jamais le nom du case.
        expect(DB::table('exercises')->where('id', $exercise->id)->value('category'))
            ->toBe($category->value);

        $reloaded = Exercise::findOrFail($exercise->id);

        // toBe() compare par identité : sans le cast on récupérerait une string.
        expect($reloaded->category)->toBe($category)
            ->and($reloaded->toArray()['category'])->toBe($category->value);
    })->with([
        'Pectoraux' => [ExerciseCategory::Pectoraux],
        'Épaules' => [ExerciseCategory::Epaules],
        'Dos' => [ExerciseCategory::Dos],
        'Jambes' => [ExerciseCategory::Jambes],
        'Bras' => [ExerciseCategory::Bras],
        'Abdominaux' => [ExerciseCategory::Abdominaux],
        'Cardio' => [ExerciseCategory::Cardio],
    ]);
});
