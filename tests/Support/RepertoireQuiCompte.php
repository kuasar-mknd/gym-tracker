<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Override;

/**
 * Un magasin de cache en mémoire qui note, dans l'ordre, chaque opération qu'on
 * lui demande : la forme du travail d'un refus se compare d'un cas à l'autre
 * sans mesurer de durée. Seule l'opération demandée de l'extérieur est notée,
 * non celles que le dépôt s'adresse à lui-même.
 */
final class RepertoireQuiCompte extends Repository
{
    /** @var list<string> */
    public static array $operations = [];

    private static int $profondeur = 0;

    public function __construct()
    {
        parent::__construct(new ArrayStore());
    }

    /**
     * @param  array<array-key, mixed>|string|\UnitEnum  $key
     */
    #[Override]
    public function get($key, $default = null): mixed
    {
        return self::noter('get', fn (): mixed => parent::get($key, $default));
    }

    /**
     * @param  array<array-key, mixed>|string|\UnitEnum  $key
     */
    #[Override]
    public function put($key, $value, $ttl = null): bool
    {
        return (bool) self::noter('put', fn (): mixed => parent::put($key, $value, $ttl));
    }

    /**
     * @param  array<array-key, mixed>|string|\UnitEnum  $key
     */
    #[Override]
    public function add($key, $value, $ttl = null): bool
    {
        return (bool) self::noter('add', fn (): mixed => parent::add($key, $value, $ttl));
    }

    /**
     * @param  string|\UnitEnum  $key
     */
    #[Override]
    public function increment($key, $value = 1): int|bool
    {
        $rendu = self::noter('increment', fn (): mixed => parent::increment($key, $value));

        return is_int($rendu) ? $rendu : (bool) $rendu;
    }

    /**
     * @param  array<array-key, mixed>|string|\UnitEnum  $key
     */
    #[Override]
    public function forget($key): bool
    {
        return (bool) self::noter('forget', fn (): mixed => parent::forget($key));
    }

    /**
     * @param  callable(): mixed  $operation
     */
    private static function noter(string $nom, callable $operation): mixed
    {
        if (self::$profondeur === 0) {
            self::$operations[] = $nom;
        }

        self::$profondeur++;

        try {
            return $operation();
        } finally {
            self::$profondeur--;
        }
    }
}
