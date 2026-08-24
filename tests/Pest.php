<?php

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Tests\TestCase;

uses(TestCase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

/**
 * A registry described into the live index, filled by hand.
 *
 * Here rather than in a test file because two suites share it and PHPUnit's file inclusion order is
 * not a thing to lean on.
 *
 * @param  array<string, mixed>  $entries
 * @param  array<string, array{0: mixed, 1: string}>  $gated  entry plus the ability reading it requires
 */
function registryAt(string $root, array $entries = [], array $gated = []): BasicRegistry
{
    $store = new BasicRegistry(new IsRegistry(
        root: $root,
        of: 'test entries',
        arity: RegistryArity::PickOne,
    ));

    foreach ($entries as $key => $entry) {
        $store->register($key, $entry, by: 'tests');
    }

    foreach ($gated as $key => [$entry, $ability]) {
        $store->register($key, $entry, by: 'tests', ability: $ability);
    }

    app(RegistryIndex::class)->describe($store);

    return $store;
}

/** The bluntest possible host policy, for proving that a read filters at all. */
function denyEverything(): Authorizer
{
    return new class implements Authorizer
    {
        public function allows(string $ability, RegistryKey $key): bool
        {
            return false;
        }
    };
}
