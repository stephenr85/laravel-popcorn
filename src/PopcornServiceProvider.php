<?php

namespace Rushing\Popcorn\Laravel;

use Illuminate\Support\ServiceProvider;
use Rushing\Popcorn\InvocableRegistry;

class PopcornServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InvocableRegistry::class);
    }

    public function boot(): void {}
}
