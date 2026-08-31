<?php

namespace Rushing\Popcorn\Laravel;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Rushing\Popcorn\InvocableRegistry;
use Rushing\Popcorn\Laravel\Baking\BakedRegistryManifest;
use Rushing\Popcorn\Laravel\Baking\DeclaredRegistryScan;
use Rushing\Popcorn\Laravel\Baking\TestEnvironmentBake;
use Rushing\Popcorn\Laravel\Console\CacheRegistriesCommand;
use Rushing\Popcorn\Laravel\Console\ClearRegistriesCommand;
use Rushing\Popcorn\Laravel\Console\KeysCommand;
use Rushing\Popcorn\Laravel\Console\RegistriesCommand;
use Rushing\Popcorn\Laravel\Facades\Popcorn;
use Rushing\Popcorn\Registries\Exceptions\UnbakedRegistryIndex;
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

            // ⚠️ ABSENT means two different things and treating them as one was the error D6 corrected.
            //
            //   absent in a BUILT HOST      -> a broken build. THROW, loudly, at the door (D3.2).
            //   absent in an UNBUILT ENV    -> nothing has built anything yet. Supply it (D6).
            //
            // A testbench does not run a deploy pipeline, so an artifact it never had is not a failure
            // — it is a missing fixture, exactly as un-migrated tables are for `RefreshDatabase`. This
            // does NOT soften D3.2: the signal being hardened is "a deployed host that forgot to bake
            // must not answer quietly", and the `else` below is untouched.
            //
            // It fires on ABSENT and never on DISAGREES. A present artifact wins in every environment,
            // however stale — a wrong artifact at a built host is a conformance failure the audit owns,
            // and papering over it here would hide the one thing the bake exists to make visible.
            if ($baked === null) {
                if (! $app->environment('testing')) {
                    return $index->markUnbaked(UnbakedRegistryIndex::at(
                        $app->make(BakedRegistryManifest::class)->path(),
                        BakedRegistryManifest::COMMAND,
                    )->reason);
                }

                $baked = $app->make(TestEnvironmentBake::class)->map();
            }

            foreach ($baked as $root => $entry) {
                $index->describeLazily((string) $root, $entry['class'], by: $entry['by']);
            }

            return $index;
        });

        $this->app->singleton(BakedRegistryManifest::class, fn ($app) => new BakedRegistryManifest($app));
        $this->app->singleton(DeclaredRegistryScan::class, fn ($app) => new DeclaredRegistryScan($app));
        $this->app->singleton(TestEnvironmentBake::class, fn ($app) => new TestEnvironmentBake($app));

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
