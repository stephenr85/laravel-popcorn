<?php

namespace Rushing\Popcorn;

use Illuminate\Support\ServiceProvider;

class PopcornServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InvocableRegistry::class);
    }

    public function boot(): void {}
}
