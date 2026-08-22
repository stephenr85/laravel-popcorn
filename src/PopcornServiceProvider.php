<?php

namespace Rushing\Popcorn\Laravel;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Rushing\Popcorn\InvocableRegistry;
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
    }
}
