<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

uses(Tests\TestCase::class);

it('gives every database-touching PHPUnit class in tests/Feature its own database', function (): void {
    $files = Finder::create()
        ->files()
        ->in(base_path('tests/Feature'))
        ->name('*.php');

    $unisolated = [];

    foreach ($files as $file) {
        $source = $file->getContents();

        // tests/Pest.php wires RefreshDatabase with pest()->use(...)->in('Feature'),
        // which reaches Pest files only. Classic PHPUnit classes are skipped silently,
        // and a sequential run hides it: they inherit the schema another test migrated.
        // Under `artisan test -p` each process gets its own database and they break.
        if (! preg_match('/^class \w+ extends TestCase$/m', $source)) {
            continue;
        }

        $touchesDatabase = preg_match('/::factory\(\)|->create\(|actingAs\(/', $source) === 1;

        if ($touchesDatabase && ! str_contains($source, 'use RefreshDatabase;')) {
            $unisolated[] = $file->getRelativePathname();
        }
    }

    expect($unisolated)->toBe([]);
});
