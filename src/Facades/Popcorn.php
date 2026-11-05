<?php

namespace Rushing\Popcorn\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Rushing\Popcorn\Laravel\PopcornManager;
use Rushing\Popcorn\Registries\Registrant;

/**
 * Front door to the registry kernel: resolve any key in the estate without naming a registry first.
 *
 * The accessor is {@see PopcornManager}, not a kernel type — see that class for why the Laravel-side
 * root has to exist, and why its surface is closed. `pop`/`tryPop` are this side's words; the kernel
 * interface stays `resolve()`/`tryResolve()` (registry-kernel tickets 06 D14/D15, 13 D6, 20).
 *
 * ```php
 * Popcorn::pop('beam.particle.resources.invoices');   // routed by longest prefix, no registry named
 * Popcorn::registry('beam.particle.resources');       // the owning registry object, with its sugar
 *
 * Popcorn::registeredBy('splicewire/laravel-beam', function (): void {
 *     // every register() in here that names no $by records 'splicewire/laravel-beam'
 * });
 * ```
 *
 * @method static mixed pop(\Rushing\Popcorn\Registries\RegistryKey|string $key)
 * @method static mixed tryPop(\Rushing\Popcorn\Registries\RegistryKey|string $key)
 * @method static object|null registry(\Rushing\Popcorn\Registries\RegistryKey|string $root)
 * @method static \Rushing\Popcorn\Registries\Registry<mixed>|null routeTo(\Rushing\Popcorn\Registries\RegistryKey|string $key)
 * @method static \Rushing\Popcorn\Registries\RegistryIndex index()
 * @method static \Rushing\Popcorn\Laravel\PopcornManager authorizeWith(?\Rushing\Popcorn\Registries\Authorizer $authorizer)
 * @method static list<string> suggest(\Rushing\Popcorn\Registries\RegistryKey|string $key, int $limit = 3, int $within = 3)
 * @method static list<class-string> discover(list<string> $paths, string $attributeClass, bool $instanceof = true)
 * @method static list<class-string> classesIn(list<string> $paths)
 * @method static \Rushing\Popcorn\Registries\Registrars\ConfigRegistrar configRegistrar(string $key)
 *
 * @see PopcornManager
 */
class Popcorn extends Facade
{
    /**
     * Run `$callback` with `$registrant` as the registrant of every write inside it that names nobody.
     * An explicit `$by` always wins. See {@see Registrant} for the vocabulary (`vendor/name`,
     * `config:<key>`, `class:<FQCN>`) and why the scope covers only what runs inside the callback.
     *
     * A static method on the facade rather than a manager call, so a provider can use it from
     * `register()` before `PopcornServiceProvider` has bound the manager: the scope is kernel state,
     * not container state.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function registeredBy(string $registrant, callable $callback): mixed
    {
        return Registrant::scoped($registrant, $callback);
    }

    protected static function getFacadeAccessor(): string
    {
        return PopcornManager::class;
    }
}
