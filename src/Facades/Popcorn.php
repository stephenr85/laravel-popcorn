<?php

namespace Rushing\Popcorn\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Rushing\Popcorn\Laravel\PopcornManager;

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
    protected static function getFacadeAccessor(): string
    {
        return PopcornManager::class;
    }
}
