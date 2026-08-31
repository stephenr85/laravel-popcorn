<?php

namespace Rushing\Popcorn\Laravel;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Rushing\Popcorn\InvocableRegistry;
use Rushing\Popcorn\Laravel\Baking\BakedRegistryManifest;
use Rushing\Popcorn\Laravel\Baking\DeclaredRegistryScan;
use Rushing\Popcorn\Laravel\Console\CacheRegistriesCommand;
use Rushing\Popcorn\Laravel\Console\ClearRegistriesCommand;
use Rushing\Popcorn\Laravel\Console\KeysCommand;
use Rushing\Popcorn\Laravel\Console\RegistriesCommand;
use Rushing\Popcorn\Laravel\Facades\Popcorn;
use Rushing\Popcorn\Registries\RegistryIndex;

class PopcornServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InvocableRegistry::class);

        // The index is a singleton: it holds live registry instances that owner packages describe into
        // it from their own `boot()`, and a per-request instance would drop every one of them. The
        // MANAGER is scoped, following `BeamManager`'s precedent — under Octane a singleton's ambient
        // state leaks between requests and queue jobs.
        // The index is a singleton: it holds live registry instances that owner packages describe into
        // it, and a per-request instance would drop every one of them.
        //
        // ⚠️ The BAKED membership is installed HERE, in register(), not in boot() — registry-kernel 73
        // phase B. A registry must be routable from the moment anything can ask, and another package's
        // register() can ask. Installing it costs one array write per root and autoloads nothing: the
        // baked entry is `root => class-string` and the object is built on first `routeTo()`.
        $this->app->singleton(RegistryIndex::class, function ($app) {
            $index = new RegistryIndex;

            // Resolution goes through the CONTAINER, at read time. That is the half that makes the
            // collapse safe rather than merely fast: where a host binds a registry behind a configuring
            // closure, the host's own object is what answers — an eager `make()` at bake time would
            // fabricate a fresh unconfigured one instead (73 phase A, finding A3).
            $index->resolveLazilyWith(fn (string $class) => $app->make($class));

            $baked = $app->make(BakedRegistryManifest::class)->read();

            if ($baked === null) {
                // ABSENT is not EMPTY (73 D3.2). Nothing falls back to a live scan — that is the boot
                // tax D2 refused, and a boot-time scan can die outright on an uncatchable
                // E_COMPILE_ERROR. So the index is marked blind and every membership read raises,
                // rather than every `routeTo()` quietly returning null with the Gated authorizer never
                // installed on anything.
                return $index->markUnbaked(\Rushing\Popcorn\Registries\Exceptions\UnbakedRegistryIndex::at(
                    $app->make(BakedRegistryManifest::class)->path(),
                    BakedRegistryManifest::COMMAND,
                )->reason);
            }

            foreach ($baked as $root => $entry) {
                $index->describeLazily((string) $root, $entry['class'], by: $entry['by']);
            }

            return $index;
        });

        $this->app->singleton(BakedRegistryManifest::class, fn ($app) => new BakedRegistryManifest($app));
        $this->app->singleton(DeclaredRegistryScan::class, fn ($app) => new DeclaredRegistryScan($app));

        $this->app->scoped(PopcornManager::class, fn ($app) => new PopcornManager(
            $app->make(RegistryIndex::class),
        ));

        // Deliberately NOT installed here, or anywhere in this package: there is exactly one authorizer
        // for the estate, and a package installing it would decide policy for a host that never asked.
        // The host opts in with `Popcorn::authorizeWith(new GateAuthorizer(...))` — see GateAuthorizer.
    }

    public function boot(): void
    {
        if (class_exists(AliasLoader::class)) {
            AliasLoader::getInstance()->alias('Popcorn', Popcorn::class);
        }

        // Popcorn owns exactly one registry of its own, so it describes it down into the index from its
        // own provider like any other owner — the direction rule ManifestIndex established and this
        // index inherits. It is also the first estate registry to reach the index at all: until the
        // migration lands, `popcorn:registries` lists the self-hosting index and this.
        $this->app->make(RegistryIndex::class)->describe(
            $this->app->make(InvocableRegistry::class),
        );

        if ($this->app->runningInConsole()) {
            // The index of indexes. Relocated here from `splicewire/laravel-beam`'s
            // `splicewire:beam:manifests` with the index it renders (registry-kernel ticket 21) — full
            // cutover, no alias, and the beam command is gone rather than deprecated. `popcorn:keys` is
            // the entry-level half of the same question, and the two are deliberately separate
            // commands rather than a flag (registry-kernel ticket 13 D10).
            $this->commands([
                RegistriesCommand::class,
                KeysCommand::class,
                CacheRegistriesCommand::class,
                ClearRegistriesCommand::class,
            ]);

            // `optimize:clear` removes the baked list the way it removes `bootstrap/cache/packages.php`.
            // Deliberately NOT hooked into `optimize`: baking walks the filesystem and is a deploy step
            // beside `package:discover`, not something a cache warm should do implicitly.
            $this->optimizes(clear: 'popcorn:registries:clear');
        }
    }
}
