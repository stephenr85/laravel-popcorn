<?php

use Illuminate\Contracts\Auth\Access\Gate;
use Rushing\Popcorn\Laravel\Authorization\GateAuthorizer;
use Rushing\Popcorn\Laravel\Facades\Popcorn;
use Rushing\Popcorn\Laravel\PopcornManager;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Exceptions\AmbiguousRegistryMatch;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\Popcorn\Registries\Exceptions\UnregisteredRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;

/**
 * The Laravel-side front door (registry-kernel ticket 20): routing a bare key without naming a
 * registry, the two failure shapes kept distinct, the manager's closed surface, and the Gate bridge
 * being shipped rather than installed.
 */
function describeStore(string $root, array $entries = []): BasicRegistry
{
    $store = new BasicRegistry(new IsRegistry(
        root: $root,
        of: 'test entries',
        arity: RegistryArity::PickOne,
    ));

    foreach ($entries as $key => $entry) {
        $store->register($key, $entry, by: 'tests');
    }

    app(RegistryIndex::class)->describe($store);

    return $store;
}

it('routes a bare key to its owning registry without the caller naming one', function () {
    describeStore('beam.particle.resources', ['invoices' => 'the invoices resource']);
    describeStore('graph.stores', ['neo4j' => 'the neo4j store']);

    expect(Popcorn::pop('beam.particle.resources.invoices'))->toBe('the invoices resource')
        ->and(Popcorn::pop('graph.stores.neo4j'))->toBe('the neo4j store');
});

it('keeps "no such registry" and "no such entry" as different failures', function () {
    describeStore('beam.particle.resources', ['invoices' => 'a']);

    // A typo'd namespace and a typo'd leaf are different operator errors with different fixes, so
    // UnregisteredRegistry is a SIBLING of RegistryMiss and catching one must not catch the other.
    expect(fn () => Popcorn::pop('nothing.claims.this'))->toThrow(UnregisteredRegistry::class)
        ->and(fn () => Popcorn::pop('beam.particle.resources.invioces'))->toThrow(RegistryMiss::class);
});

it('returns null from tryPop for BOTH failures, but still throws on ambiguity', function () {
    describeStore('beam.particle.resources', ['invoices' => 'a', 'orders' => 'b']);

    expect(Popcorn::tryPop('nothing.claims.this'))->toBeNull()
        ->and(Popcorn::tryPop('beam.particle.resources.invioces'))->toBeNull()
        // A key naming a branch has several answers and none of them is THE answer.
        ->and(fn () => Popcorn::tryPop('beam.particle.resources'))->toThrow(AmbiguousRegistryMatch::class);
});

it('hands back the registry object by root, and the routed store by key', function () {
    $store = describeStore('beam.particle.resources', ['invoices' => 'a']);

    expect(Popcorn::registry('beam.particle.resources'))->toBe($store)
        ->and(Popcorn::routeTo('beam.particle.resources.invoices'))->toBe($store)
        ->and(Popcorn::registry('nothing.claims.this'))->toBeNull();
});

it('suggests near keys from the routed registry, nearest first', function () {
    describeStore('beam.particle.resources', [
        'invoices' => 'a',
        'invoice-lines' => 'b',
        'orders' => 'c',
    ]);

    expect(Popcorn::suggest('beam.particle.resources.invioces'))
        ->toBe(['beam.particle.resources.invoices']);
});

it('never suggests a registry ROOT in answer to a missed entry key', function () {
    describeStore('beam.particle.resources', ['invoices' => 'a']);

    // The index self-hosts, so it is an entry of itself and its keys are roots — including the empty
    // one. Offering those as "did you mean" would be a category error.
    expect(Popcorn::suggest('beam.particle.resourcs'))->not->toContain('');
});

it('binds the manager scoped and the index singleton', function () {
    // The index holds live registries described into it at boot; a per-request instance would drop
    // every one. The manager follows BeamManager and is scoped, for Octane.
    expect(app(RegistryIndex::class))->toBe(app(RegistryIndex::class))
        ->and(app(PopcornManager::class))->toBe(app(PopcornManager::class))
        ->and(app(PopcornManager::class)->index())->toBe(app(RegistryIndex::class));
});

it('ships the Gate bridge but installs no authorizer', function () {
    // Gate::allows() denies an UNDEFINED ability, so a default-installed bridge would turn "nobody
    // wrote the policy yet" into silent fleet-wide invisibility. The host opts in.
    $store = describeStore('beam.particle.resources', ['invoices' => 'a']);
    $store->register('payroll', 'b', by: 'tests', ability: 'view-payroll');

    expect(Popcorn::pop('beam.particle.resources.payroll'))->toBe('b');

    Popcorn::authorizeWith(new GateAuthorizer(app(Gate::class)));

    expect(fn () => Popcorn::pop('beam.particle.resources.payroll'))->toThrow(RegistryMiss::class)
        // The ungated entry short-circuits before the authorizer, so installing one cannot narrow an
        // already-open surface — the property that lets the default be open.
        ->and(Popcorn::pop('beam.particle.resources.invoices'))->toBe('a');
});

it('keeps its surface closed — no Macroable, no runtime extension point', function () {
    // BeamManager's precedent, adopted at birth rather than after accretion.
    expect(class_uses_recursive(PopcornManager::class))
        ->not->toContain(Illuminate\Support\Traits\Macroable::class);
});
