<?php

use Rushing\Popcorn\Laravel\Facades\Popcorn;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Registrars\ConfigRegistrar;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Tests\Fixtures\ScannedThing;

/**
 * The Laravel half of registry-kernel ticket 24: the one `config()` read the kernel is not allowed to
 * make, the `discover()` find-util, and the index's derived "how do I contribute?" column.
 */
function storeFor(string $root): BasicRegistry
{
    return new BasicRegistry(new IsRegistry(
        root: $root,
        of: 'test entries',
        arity: RegistryArity::PickOne,
    ));
}

it('hands a ConfigRegistrar the already-read config array, keyed by where it came from', function () {
    config()->set('beam.core.renderings', ['table' => 'TableRendering']);

    $registrar = Popcorn::configRegistrar('beam.core.renderings');
    $store = storeFor('beam.renderings');

    $store->attach($registrar);

    expect($registrar)->toBeInstanceOf(ConfigRegistrar::class)
        ->and($registrar->source())->toBe('config beam.core.renderings')
        ->and($store->resolve('table'))->toBe('TableRendering');
});

it('treats absent config as an empty registrar, not an error', function () {
    $store = storeFor('beam.renderings');

    $store->attach(Popcorn::configRegistrar('beam.core.nothing-published-here'));

    expect($store->keys())->toBe([]);
});

it('discovers annotated class-strings and touches no registry doing it', function () {
    $found = Popcorn::discover([__DIR__.'/../Fixtures'], ScannedThing::class);

    expect($found)->toBe([Rushing\Popcorn\Tests\Fixtures\AnnotatedThing::class])
        // The index is untouched: `discover()` is a finder, not a fill surface. Sugaring one of the
        // registrars onto the facade would have implied the attribute one is privileged (07 D10).
        // Two describes at boot, not one, since ticket 30: the self-hosting index and this package's own
        // `popcorn.invocables` — the first estate registry to reach the index at all.
        ->and(app(RegistryIndex::class)->unfiltered()->keys())->toHaveCount(2);
});

it('renders a registry fill sources in popcorn:registries, derived rather than declared', function () {
    config()->set('beam.core.renderings', ['table' => 'TableRendering']);

    $store = storeFor('beam.renderings');
    $store->attach(Popcorn::configRegistrar('beam.core.renderings'));

    app(RegistryIndex::class)->describe($store);

    $this->artisan('popcorn:registries')
        ->expectsOutputToContain('config beam.core.renderings')
        ->assertSuccessful();
});

it('says hand for a registry nothing fills automatically', function () {
    app(RegistryIndex::class)->describe(storeFor('beam.renderings'));

    $this->artisan('popcorn:registries')
        ->expectsOutputToContain('hand')
        ->assertSuccessful();
});
