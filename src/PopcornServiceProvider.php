<?php

namespace Rushing\Popcorn\Laravel;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Rushing\Popcorn\InvocableRegistry;
use Rushing\Popcorn\Laravel\Facades\Popcorn;

class PopcornServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InvocableRegistry::class);
    }

    public function boot(): void
    {
        if (class_exists(AliasLoader::class)) {
            AliasLoader::getInstance()->alias('Popcorn', Popcorn::class);
        }
    }
}
