<?php

namespace Rushing\Popcorn\Tests\Fixtures;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * The LIST-shaped config registry, modelled on `schemastud/laravel-data-schemas`'
 * `data-schemas.strategies` — an ordered pipeline of class-strings that five packages append to from
 * their own providers, keyed by {@see Key::fromClass()} because a list has no keys of its own.
 */
#[IsRegistry(
    root: 'fixture.strategies',
    of: 'an ordered strategy pipeline',
    arity: RegistryArity::RunAll,
    onDuplicate: OnDuplicate::Supersede,
)]
class PipelineStrategiesRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'fixture.strategies';
    }

    protected function keyFor(int|string $index, mixed $entry): RegistryKey|string
    {
        return Key::fromClass($entry);
    }
}
