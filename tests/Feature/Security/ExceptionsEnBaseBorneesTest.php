<?php

declare(strict_types=1);

use App\Models\ExceptionEnregistree;
use App\Support\AdmissionDesExceptions;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * Une panne qui répète la même erreur (Redis saturé, une API muette) écrivait
 * une ligne de 26 à 30 Kio par occurrence : 11 264 lignes, 334 Mo, et chaque
 * écriture retient un worker. Une ligne complète par empreinte et par
 * fenêtre, un plafond par minute, le reste se compte dans le cache.
 */
beforeEach(function (): void {
    $this->travelTo('2026-11-19 10:00:00');

    Route::post('/_boum', function (): never {
        throw new RuntimeException('boum '.request()->string('detail'));
    });
});

function exceptionsEnBaseEcritures(): int
{
    return count(array_filter(
        DB::getQueryLog(),
        static fn (array $requete): bool => str_starts_with(strtolower($requete['query']), 'insert into `filament_exceptions_table`'),
    ));
}

it('garde une seule ligne pour N exceptions identiques, avec leur compte', function (): void {
    foreach (range(1, 5) as $_) {
        $this->post('/_boum', ['detail' => 'x'])->assertStatus(500);
    }

    $ligne = ExceptionEnregistree::query()->sole();

    expect($ligne->empreinte)->toBeString()->toHaveLength(32)
        ->and($ligne->occurrences)->toBe(1);

    $this->travel(AdmissionDesExceptions::FENETRE_EN_SECONDES + 1)->seconds();
    $this->post('/_boum', ['detail' => 'x'])->assertStatus(500);

    expect(ExceptionEnregistree::query()->count())->toBe(2)
        ->and(ExceptionEnregistree::query()->orderBy('id')->pluck('occurrences')->all())->toBe([5, 1]);
});

it('porte sur la ligne le compte d’une rafale qui s’arrête, sans attendre la fenêtre suivante', function (): void {
    foreach (range(1, 11) as $_) {
        $this->post('/_boum', ['detail' => 'x'])->assertStatus(500);
    }

    expect(ExceptionEnregistree::query()->sole()->occurrences)->toBe(11);

    $this->travel(AdmissionDesExceptions::FENETRE_EN_SECONDES + 1)->seconds();
    $this->post('/_boum', ['detail' => 'x'])->assertStatus(500);

    expect(ExceptionEnregistree::query()->orderBy('id')->pluck('occurrences')->all())->toBe([11, 1]);
});

it('écrit une nouvelle ligne pour une exception différente', function (): void {
    Route::post('/_autre', function (): never {
        throw new LogicException('autre chose');
    });

    $this->post('/_boum', ['detail' => 'x'])->assertStatus(500);
    $this->post('/_autre')->assertStatus(500);
    $this->post('/_boum', ['detail' => 'x'])->assertStatus(500);

    expect(ExceptionEnregistree::query()->count())->toBe(2)
        ->and(ExceptionEnregistree::query()->orderBy('id')->pluck('type')->all())->toBe([RuntimeException::class, LogicException::class]);
});

it('compte pour une seule les exceptions que ne distingue qu’une valeur variable', function (): void {
    $this->post('/_boum', ['detail' => 'range/A1B2C et 4512'])->assertStatus(500);
    $this->post('/_boum', ['detail' => 'range/9F8E7 et 77'])->assertStatus(500);
    $this->post('/_boum', ['detail' => 'range/00000 et 3'])->assertStatus(500);

    expect(ExceptionEnregistree::query()->count())->toBe(1);
});

it('n’écrit pas plus de lignes par minute que le plafond, quelles que soient les empreintes', function (): void {
    Route::post('/_variees', function (): never {
        throw new RuntimeException('tour '.str_repeat('z', request()->integer('n')));
    });

    DB::enableQueryLog();

    foreach (range(1, AdmissionDesExceptions::PLAFOND_PAR_MINUTE + 10) as $n) {
        $this->post('/_variees', ['n' => $n])->assertStatus(500);
    }

    expect(ExceptionEnregistree::query()->count())->toBe(AdmissionDesExceptions::PLAFOND_PAR_MINUTE)
        ->and(exceptionsEnBaseEcritures())->toBe(AdmissionDesExceptions::PLAFOND_PAR_MINUTE);

    $this->travel(61)->seconds();
    $this->post('/_variees', ['n' => 99])->assertStatus(500);

    expect(ExceptionEnregistree::query()->count())->toBe(AdmissionDesExceptions::PLAFOND_PAR_MINUTE + 1);
});

it('n’insère rien et n’écrit qu’un total pour vingt répétitions dans la fenêtre', function (): void {
    $this->post('/_boum', ['detail' => 'x'])->assertStatus(500);

    DB::enableQueryLog();
    DB::flushQueryLog();

    foreach (range(1, 20) as $_) {
        $this->post('/_boum', ['detail' => 'x'])->assertStatus(500);
    }

    $ecritures = collect(DB::getQueryLog())
        ->map(static fn (array $requete): string => strtolower($requete['query']))
        ->filter(static fn (string $requete): bool => str_contains($requete, 'filament_exceptions_table') && ! str_starts_with($requete, 'select'));

    expect($ecritures->filter(static fn (string $requete): bool => str_starts_with($requete, 'insert')))->toBeEmpty()
        ->and($ecritures->filter(static fn (string $requete): bool => str_starts_with($requete, 'update')))->toHaveCount(1);
});

it('garde l’exception et reste borné quand le cache refuse', function (): void {
    Cache::extend('sature', fn () => Cache::repository(new class() extends ArrayStore
    {
        /**
         * @param  string  $key
         * @param  mixed  $value
         * @param  int  $seconds
         */
        public function add($key, $value, $seconds): bool
        {
            throw new RuntimeException('OOM command not allowed');
        }
    }));
    config(['cache.stores.sature' => ['driver' => 'sature'], 'cache.default' => 'sature']);

    foreach (range(1, 4) as $_) {
        $this->post('/_boum', ['detail' => 'x'])->assertStatus(500);
    }

    expect(ExceptionEnregistree::query()->count())->toBe(1);
});

it('ne rattache aucune empreinte à une ligne écrite hors du rapport', function (): void {
    $ligne = ExceptionEnregistree::query()->create([
        'type' => RuntimeException::class, 'code' => '0', 'message' => 'm', 'file' => 'f.php', 'line' => 1,
        'trace' => [], 'method' => 'GET', 'path' => '/',
    ]);

    expect($ligne->empreinte)->toBeNull()
        ->and(ExceptionEnregistree::query()->whereKey($ligne->id)->value('empreinte'))->toBeNull();
});
