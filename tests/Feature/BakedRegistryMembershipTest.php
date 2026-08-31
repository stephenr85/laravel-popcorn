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

it('marks the index UNBAKED when the artifact is absent in a BUILT environment', function () {
    // ⚠️ This assertion used to run in the testing environment and is now pinned to a built one.
    // Registry-kernel 73 D6 split ABSENT in two: absent in a built host is a broken build and throws;
    // absent in a testbench is a missing fixture and is supplied. The behaviour under test did not
    // change — the environment it has to be asserted in did.
    //
    // With no artifact the index must be BLIND rather than empty. A test that only asserted
    // `keys() === []` would pass against the catastrophe this ruling exists to prevent.
    app(BakedRegistryManifest::class)->clear();
    app()->detectEnvironment(fn () => 'production');
    app()->forgetInstance(RegistryIndex::class);

    $index = app(RegistryIndex::class);

    expect($index->isUnbaked())->toBeTrue()
        ->and(fn () => $index->routeTo('anything'))->toThrow(UnbakedRegistryIndex::class);

    app()->detectEnvironment(fn () => 'testing');
    app()->forgetInstance(RegistryIndex::class);
});

it('takes the baked roots LAZILY, constructing nothing at boot', function () {
    app(BakedRegistryManifest::class)->write([
        'popcorn.invocables' => ['class' => Rushing\Popcorn\InvocableRegistry::class, 'by' => 'bake'],
    ]);
    app()->forgetInstance(RegistryIndex::class);

    $index = app(RegistryIndex::class);

    expect($index->isUnbaked())->toBeFalse()
        ->and($index->pending())->toHaveKey('popcorn.invocables')
        ->and($index->routeTo('popcorn.invocables.anything'))
        ->toBeInstanceOf(Rushing\Popcorn\InvocableRegistry::class);

    app(BakedRegistryManifest::class)->clear();
});

it('resolves a baked root through the CONTAINER, so a host binding is what answers', function () {
    $configured = new Rushing\Popcorn\InvocableRegistry;
    app()->instance(Rushing\Popcorn\InvocableRegistry::class, $configured);

    app(BakedRegistryManifest::class)->write([
        'popcorn.invocables' => ['class' => Rushing\Popcorn\InvocableRegistry::class, 'by' => 'bake'],
    ]);
    app()->forgetInstance(RegistryIndex::class);

    // Phase A's A3: where a host binds a registry behind a configuring closure, an eager `make()` at
    // bake time fabricates a fresh unconfigured one. Read-time resolution is what makes the host's own
    // object the one that answers.
    expect(app(RegistryIndex::class)->routeTo('popcorn.invocables.x'))->toBe($configured);

    app(BakedRegistryManifest::class)->clear();
});

/**
 * ⚠️ THE PRODUCTION THROW — registry-kernel 73 D6 requirement 3.
 *
 * Once the testing environment bakes automatically, **no suite exercises the throw incidentally any
 * more**. This test is the only thing standing between D3.2's ruling and a silently-empty production
 * index, so it is written to fail if the branch is removed, and that was verified by mutation rather
 * than assumed (delete the `! environment('testing')` guard and this test is the one that goes red).
 */
it('THROWS outside the testing environment when the artifact is absent', function () {
    app(BakedRegistryManifest::class)->clear();

    // Not a mock of the environment — the real container answering as a built host would.
    app()->detectEnvironment(fn () => 'production');
    app()->forgetInstance(RegistryIndex::class);

    $index = app(RegistryIndex::class);

    expect($index->isUnbaked())->toBeTrue()
        ->and(fn () => $index->routeTo('anything'))->toThrow(UnbakedRegistryIndex::class);

    app()->detectEnvironment(fn () => 'testing');
    app()->forgetInstance(RegistryIndex::class);
});

it('bakes automatically INSIDE the testing environment, because there is no build to have run', function () {
    app(BakedRegistryManifest::class)->clear();
    Rushing\Popcorn\Laravel\Baking\TestEnvironmentBake::forget();
    app()->forgetInstance(RegistryIndex::class);

    $index = app(RegistryIndex::class);

    // A testbench does not run a deploy pipeline, so an artifact it never had is a missing FIXTURE and
    // not a broken build — the distinction D6 corrected D5 on.
    expect($index->isUnbaked())->toBeFalse()
        ->and($index->pending())->not->toBe([])
        ->and($index->routeTo('popcorn.invocables.x'))
        ->toBeInstanceOf(Rushing\Popcorn\InvocableRegistry::class);
});

it('fires on ABSENT and never on DISAGREES — a present artifact wins even when it is wrong', function () {
    // A built host whose artifact is stale is a DIFFERENT failure, and this branch must not paper over
    // it. The artifact below is deliberately wrong in a way a rescan CANNOT reproduce: it keys the real
    // registry under a root no `#[IsRegistry]` on disk declares.
    //
    // ⚠️ The first version of this test wrote `popcorn.invocables` — which is exactly the one root this
    // package's own testbench scan finds — so the honoured-artifact path and the rescan path produced
    // identical results and the test passed against a mutation that rescanned unconditionally. A
    // fixture that a rescan would recreate cannot detect a rescan. Caught by mutation, not by reading.
    app(BakedRegistryManifest::class)->write([
        'stale.root.nothing.declares' => ['class' => Rushing\Popcorn\InvocableRegistry::class, 'by' => 'stale'],
    ]);
    Rushing\Popcorn\Laravel\Baking\TestEnvironmentBake::forget();
    app()->forgetInstance(RegistryIndex::class);

    expect(array_keys(app(RegistryIndex::class)->pending()))->toBe(['stale.root.nothing.declares']);

    app(BakedRegistryManifest::class)->clear();
});

it('bakes ONCE per process, not once per test boot', function () {
    Rushing\Popcorn\Laravel\Baking\TestEnvironmentBake::forget();
    app(BakedRegistryManifest::class)->clear();

    $bake = app(Rushing\Popcorn\Laravel\Baking\TestEnvironmentBake::class);

    $first = $bake->map();
    $t = microtime(true);
    $second = $bake->map();
    $memoised = (microtime(true) - $t) * 1000;

    // A scan per boot across 29 suites is a tax paid thousands of times for an answer that cannot
    // change within a process. Asserting the memo is REAL rather than assuming it: the second call is
    // sub-millisecond, and identical.
    expect($second)->toBe($first)
        ->and($memoised)->toBeLessThan(1.0);
});
