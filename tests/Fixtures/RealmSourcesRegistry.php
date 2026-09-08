<?php

namespace Rushing\Popcorn\Tests\Fixtures;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;

/**
 * The MAP-shaped config registry, modelled on `splicewire/laravel-beam`'s `beam.client.sources` — a
 * realm-keyed array of class-strings where an unbound realm is spelled `null`.
 */
#[IsRegistry(
    root: 'fixture.client.sources',
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    description: 'route manifest sources by realm',
)]
class RealmSourcesRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'fixture.client.sources';
    }
}
