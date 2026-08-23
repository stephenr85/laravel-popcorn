<?php

namespace Rushing\Popcorn\Laravel;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Rushing\Popcorn\InvocableRegistry;
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
        $this->app->singleton(RegistryIndex::class);

        $this->app->scoped(PopcornManager::class, fn ($app) => new PopcornManager(
            $app->make(RegistryIndex::class),
            $app->make(InvocableRegistry::class),
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
            // cutover, no alias, and the beam command is gone rather than deprecated.
            $this->commands([RegistriesCommand::class]);
        }
    }
}
