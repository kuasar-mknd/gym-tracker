<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use App\Services\Diagnostic\ValeurLue;
use Closure;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Des occurrences groupées : pour chaque groupe, ses champs, le nombre
 * d'occurrences, la première et la dernière, et l'identifiant de la
 * dernière. Les lignes arrivent de la plus récente à la plus ancienne ; les
 * groupes sortent triés par les champs donnés, du plus grand au plus petit,
 * puis par la dernière occurrence.
 */
final class RegroupeLesOccurrences
{
    /**
     * @param  Collection<int, stdClass>  $lignes
     * @param  Closure(stdClass): string  $cle
     * @param  Closure(stdClass, Collection<int, stdClass>): array<string, mixed>  $champs  d'après la dernière occurrence et toutes
     * @param  list<string>  $tri
     * @return Collection<int, array<string, mixed>>
     */
    public function __invoke(Collection $lignes, Closure $cle, Closure $champs, array $tri = ['nombre']): Collection
    {
        return $lignes
            ->groupBy($cle)
            ->map(static fn (Collection $occurrences): array => self::groupe($occurrences, $champs))
            ->sortBy(array_map(static fn (string $champ): array => [$champ, 'desc'], [...$tri, 'dernier_id']))
            ->values();
    }

    /**
     * @param  Collection<array-key, stdClass>  $occurrences
     * @param  Closure(stdClass, Collection<int, stdClass>): array<string, mixed>  $champs
     * @return array<string, mixed>
     */
    private static function groupe(Collection $occurrences, Closure $champs): array
    {
        $toutes = $occurrences->values();
        $derniere = $toutes->firstOrFail();

        return [
            ...$champs($derniere, $toutes),
            'nombre' => $toutes->count(),
            'premiere_le' => ValeurLue::instant($toutes->reverse()->firstOrFail()->created_at),
            'derniere_le' => ValeurLue::instant($derniere->created_at),
            'dernier_id' => ValeurLue::entier($derniere->id),
        ];
    }
}
