<?php

namespace Rushing\Popcorn\Tests\Fixtures\RegistryDocs;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryKey;

/** popcorn:registries:docs fixture: the one root the covered fixture package declares. */
#[IsRegistry(root: 'fixture.docs.covered')]
class CoveredFixtureRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'fixture.docs.covered';
    }

    protected function keyFor(int|string $index, mixed $entry): RegistryKey|string
    {
        return (string) $index;
    }
}
