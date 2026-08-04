<?php

namespace Rushing\Popcorn\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Rushing\Popcorn\InvocableRegistry;

/**
 * Ergonomic front door to the json-ns handler dispatch.
 *
 * @method static array invoke(string $name, array $input)
 * @method static bool has(string $name)
 *
 * @see InvocableRegistry
 */
class Popcorn extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return InvocableRegistry::class;
    }

    /**
     * Dispatch a json-ns handler by URI with the given envelope.
     *
     * Resolves the underlying {@see InvocableRegistry} and calls
     * `invoke($name, array $input)` — the handler-dispatch front door.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public static function invoke(string $uri, array $envelope): array
    {
        return static::getFacadeRoot()->invoke($uri, $envelope);
    }
}
