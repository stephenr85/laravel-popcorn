<?php

use Rushing\Popcorn\Laravel\Baking\BakedRegistryManifest;
use Rushing\Popcorn\Registries\Exceptions\UnbakedRegistryIndex;
use Rushing\Popcorn\Registries\RegistryIndex;

/**
 * The baked membership artifact — registry-kernel ticket 73 phase B, on D3.
 *
 * The load-bearing assertions are the ones about ABSENCE, because absence is the state every failure
 * mode of a filesystem produces and it is indistinguishable from a healthy empty estate unless something
 * keeps them apart on purpose.
 */
it('reads null for an ABSENT artifact and an array for a present-but-empty one', function () {
    $manifest = app(BakedRegistryManifest::class);
    $manifest->clear();

    // The whole of D3.2 in two assertions: these must never collapse into one empty array. `null` means
    // "I was never baked" and `[]` means "this host declares nothing", and only the first is loud.
    expect($manifest->read())->toBeNull();

    $manifest->write([]);

    expect($manifest->read())->toBe([]);

    $manifest->clear();
});

it('round-trips a baked map through an opcache-friendly php file', function () {
    $manifest = app(BakedRegistryManifest::class);

    $manifest->write(['beam.realm' => ['class' => RegistryIndex::class, 'by' => 'test']]);

    expect($manifest->read())->toBe(['beam.realm' => ['class' => RegistryIndex::class, 'by' => 'test']])
        ->and(file_get_contents($manifest->path()))->toContain('do not commit');

    $manifest->clear();
});

it('writes the artifact where `popcorn:registries:cache` says it does', function () {
    $manifest = app(BakedRegistryManifest::class);
    $manifest->clear();

    $this->artisan('popcorn:registries:cache')->assertSuccessful();

    expect($manifest->exists())->toBeTrue()
        ->and($manifest->read())->toBeArray();

    $manifest->clear();
});

it('clears the artifact and says nothing falls back to a scan', function () {
    $manifest = app(BakedRegistryManifest::class);
    $manifest->write(['x' => ['class' => RegistryIndex::class, 'by' => 't']]);

    $this->artisan('popcorn:registries:clear')
        ->expectsOutputToContain('raises UnbakedRegistryIndex')
        ->assertSuccessful();

    expect($manifest->exists())->toBeFalse();
});

it('marks the index UNBAKED when the artifact is absent, rather than serving an empty one', function () {
    // The provider builds the index; with no artifact it must be blind rather than empty. A test that
    // only asserted `keys() === []` would pass against the catastrophe this ruling exists to prevent.
    app(BakedRegistryManifest::class)->clear();
    app()->forgetInstance(RegistryIndex::class);

    $index = app(RegistryIndex::class);

    expect($index->isUnbaked())->toBeTrue()
        ->and(fn () => $index->routeTo('anything'))->toThrow(UnbakedRegistryIndex::class);
});

it('takes the baked roots LAZILY, constructing nothing at boot', function () {
    app(BakedRegistryManifest::class)->write([
        'popcorn.invocables' => ['class' => \Rushing\Popcorn\InvocableRegistry::class, 'by' => 'bake'],
    ]);
    app()->forgetInstance(RegistryIndex::class);

    $index = app(RegistryIndex::class);

    expect($index->isUnbaked())->toBeFalse()
        ->and($index->pending())->toHaveKey('popcorn.invocables')
        ->and($index->routeTo('popcorn.invocables.anything'))
        ->toBeInstanceOf(\Rushing\Popcorn\InvocableRegistry::class);

    app(BakedRegistryManifest::class)->clear();
});

it('resolves a baked root through the CONTAINER, so a host binding is what answers', function () {
    $configured = new \Rushing\Popcorn\InvocableRegistry;
    app()->instance(\Rushing\Popcorn\InvocableRegistry::class, $configured);

    app(BakedRegistryManifest::class)->write([
        'popcorn.invocables' => ['class' => \Rushing\Popcorn\InvocableRegistry::class, 'by' => 'bake'],
    ]);
    app()->forgetInstance(RegistryIndex::class);

    // Phase A's A3: where a host binds a registry behind a configuring closure, an eager `make()` at
    // bake time fabricates a fresh unconfigured one. Read-time resolution is what makes the host's own
    // object the one that answers.
    expect(app(RegistryIndex::class)->routeTo('popcorn.invocables.x'))->toBe($configured);

    app(BakedRegistryManifest::class)->clear();
});
