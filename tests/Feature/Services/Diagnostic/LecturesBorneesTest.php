<?php

declare(strict_types=1);

use App\Services\Diagnostic\Projections\LecturesBornees;
use Illuminate\Support\Facades\DB;

/*
 * Le compte d'une lecture bornée : il s'arrête à 5 000 lignes, et ne dit
 * « plafonné » qu'à la ligne de plus.
 */
it('ne dit le compte plafonné qu’au-delà de 5 000 lignes', function (int $lignes, int $total, bool $plafonne): void {
    $ligne = [
        'type' => RuntimeException::class, 'code' => '0', 'message' => 'boum', 'file' => 'a.php', 'line' => 1,
        'trace' => '[]', 'method' => 'GET', 'path' => '/', 'created_at' => now(), 'updated_at' => now(),
    ];

    foreach (array_chunk(array_fill(0, $lignes, $ligne), 1_000) as $lot) {
        DB::table('filament_exceptions_table')->insert($lot);
    }

    expect(LecturesBornees::compter(DB::table('filament_exceptions_table')))->toBe(['total' => $total, 'plafonne' => $plafonne]);
})->with([
    'sous la borne' => [3, 3, false],
    'à la borne' => [LecturesBornees::LIGNES_AU_PLUS, LecturesBornees::LIGNES_AU_PLUS, false],
    'une ligne de plus' => [LecturesBornees::LIGNES_AU_PLUS + 1, LecturesBornees::LIGNES_AU_PLUS, true],
]);
