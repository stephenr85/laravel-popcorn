<?php

namespace Rushing\Popcorn\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PopcornServiceProvider::class];
    }
}
