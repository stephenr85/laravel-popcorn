<?php

use Illuminate\Support\Facades\Artisan;
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
function registryAt(string $root, array $entries = [], array $gated = [], RegistryArity|array $arity = RegistryArity::PickOne): BasicRegistry
{
    $store = new BasicRegistry(new IsRegistry(
        root: $root,
        of: 'test entries',
        arity: $arity,
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

/**
 * A command's full rendered output.
 *
 * `$this->artisan()`'s pending assertions test line-by-line expectations; a table cell and a legend that
 * must both be present, or present exactly once, are assertions about the whole buffer.
 *
 * @param  array<string, mixed>  $arguments
 */
function commandOutput(string $command, array $arguments = []): string
{
    Artisan::call($command, $arguments);

    return Artisan::output();
}

/** The same, under `--json` — the projection ticket 16 treats as the presumptive wire shape. */
function commandJson(string $command, array $arguments = []): string
{
    return commandOutput($command, $arguments + ['--json' => true]);
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
