<?php

namespace Rushing\Popcorn\Tests\Fixtures;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * The MAP-shaped config registry, modelled on `splicewire/laravel-beam`'s `beam.client.sources` — a
 * realm-keyed array of class-strings where an unbound realm is spelled `null`.
 */
#[IsRegistry(
    root: 'fixture.client.sources',
    of: 'route manifest sources by realm',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
)]
class RealmSourcesRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'fixture.client.sources';
    }
}
