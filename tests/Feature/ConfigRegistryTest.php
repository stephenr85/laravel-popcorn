<?php

use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\Registrars\ConfigRegistrar;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Tests\Fixtures\PipelineStrategiesRegistry;
use Rushing\Popcorn\Tests\Fixtures\RealmSourcesRegistry;

/**
 * `data-schemas.strategies` as it actually ships: this package seeds three, then four more packages
 * append theirs from their own providers under an `in_array` guard. The class-strings are the estate's
 * real ones — the registry only ever holds the string, so nothing needs to be autoloadable.
 */
function fiveRegistrants(): array
{
    return [
        'Schemastud\DataSchemas\Strategies\ValidationAttributeStrategy',
        'Schemastud\DataSchemas\Strategies\MigrationAttributesStrategy',
        'Schemastud\DataSchemas\Strategies\KeywordAttributesStrategy',
        'Schemastud\Frame\Strategies\WidgetAttributesStrategy',
        'Rushing\DataFilters\Schema\FilterableAttributesStrategy',
    ];
}

it('reads a map-shaped config array, keys as written', function () {
    config(['fixture.client.sources' => [
        'defaults' => 'App\Sources\TenantSource',
        'operator' => 'App\Sources\OperatorSource',
    ]]);

    $registry = app(RealmSourcesRegistry::class);

    expect($registry->resolve('defaults'))->toBe('App\Sources\TenantSource')
        ->and($registry->resolve('operator'))->toBe('App\Sources\OperatorSource')
        ->and($registry->has('defaults'))->toBeTrue();
});

it('stamps the declared root, so keys go relative in and absolute out', function () {
    config(['fixture.client.sources' => ['defaults' => 'App\Sources\TenantSource']]);

    $keys = array_map(strval(...), app(RealmSourcesRegistry::class)->keys());

    expect($keys)->toBe(['fixture.client.sources.defaults']);
});

it('accepts the absolute key too — the array sees the same slot either way', function () {
    config(['fixture.client.sources' => ['defaults' => 'App\Sources\TenantSource']]);

    $registry = app(RealmSourcesRegistry::class);

    expect($registry->resolve('fixture.client.sources.defaults'))->toBe('App\Sources\TenantSource');

    $registry->register('fixture.client.sources.operator', 'App\Sources\OperatorSource');

    expect(config('fixture.client.sources'))->toBe([
        'defaults' => 'App\Sources\TenantSource',
        'operator' => 'App\Sources\OperatorSource',
    ]);
});

it('treats a null config slot as unbound, not as an entry', function () {
    // `'operator' => env('BEAM_CLIENT_OPERATOR_SOURCE')` on a host that never set it.
    config(['fixture.client.sources' => [
        'defaults' => 'App\Sources\TenantSource',
        'operator' => null,
    ]]);

    $registry = app(RealmSourcesRegistry::class);

    expect($registry->has('operator'))->toBeFalse()
        ->and($registry->tryResolve('operator'))->toBeNull()
        ->and($registry->keys())->toHaveCount(1);
});

it('is the storage, not a copy of it — a late registrant is visible without re-resolving', function () {
    config(['fixture.client.sources' => ['defaults' => 'App\Sources\TenantSource']]);

    $registry = app(RealmSourcesRegistry::class);

    expect($registry->keys())->toHaveCount(1);

    // A provider booting later appends the way the estate's five registrants actually do.
    config(['fixture.client.sources' => array_merge(
        config('fixture.client.sources'),
        ['operator' => 'App\Sources\OperatorSource'],
    )]);

    expect($registry->has('operator'))->toBeTrue();
});

it('writes back into the config repository', function () {
    config(['fixture.client.sources' => []]);

    app(RealmSourcesRegistry::class)->register('operator', 'App\Sources\OperatorSource');

    expect(config('fixture.client.sources'))->toBe(['operator' => 'App\Sources\OperatorSource']);
});

it('misses loudly on an unregistered key', function () {
    config(['fixture.client.sources' => ['defaults' => 'App\Sources\TenantSource']]);

    app(RealmSourcesRegistry::class)->resolve('nobody');
})->throws(RegistryMiss::class);

it('refuses a key deeper than the one level a config array key is', function () {
    config(['fixture.client.sources' => []]);

    app(RealmSourcesRegistry::class)->register('operator.nested', 'App\Sources\OperatorSource');
})->throws(InvalidArgumentException::class, 'a config array key is one level');

// ---------------------------------------------------------------------------------------------
// The list shape — `data-schemas.strategies`, the hard case ticket 25 was chartered against.
// ---------------------------------------------------------------------------------------------

it('reads the five-registrant strategy pipeline, keyed from the class', function () {
    config(['fixture.strategies' => fiveRegistrants()]);

    $keys = array_map(strval(...), app(PipelineStrategiesRegistry::class)->keys());

    expect($keys)->toBe([
        'fixture.strategies.validation-attribute-strategy',
        'fixture.strategies.migration-attributes-strategy',
        'fixture.strategies.keyword-attributes-strategy',
        'fixture.strategies.widget-attributes-strategy',
        'fixture.strategies.filterable-attributes-strategy',
    ]);
});

it('preserves pipeline order — registration order is the config array order', function () {
    config(['fixture.strategies' => fiveRegistrants()]);

    expect(app(PipelineStrategiesRegistry::class)->matches('fixture.strategies'))
        ->toBe(fiveRegistrants());
});

it('resolves one strategy by its derived key', function () {
    config(['fixture.strategies' => fiveRegistrants()]);

    expect(app(PipelineStrategiesRegistry::class)->resolve('widget-attributes-strategy'))
        ->toBe('Schemastud\Frame\Strategies\WidgetAttributesStrategy');
});

it('appends to a list-shaped config and keeps it a list', function () {
    config(['fixture.strategies' => fiveRegistrants()]);

    app(PipelineStrategiesRegistry::class)
        ->register('generation-attributes-strategy', 'Splicewire\CompositionSpine\GenerationAttributesStrategy');

    $entries = config('fixture.strategies');

    expect(array_is_list($entries))->toBeTrue()
        ->and($entries)->toHaveCount(6)
        ->and($entries[5])->toBe('Splicewire\CompositionSpine\GenerationAttributesStrategy');
});

it('replaces in place rather than appending a duplicate — the estate\'s in_array guard, from the kernel', function () {
    config(['fixture.strategies' => fiveRegistrants()]);

    // What a provider re-registering on a second boot does. The `in_array` guard five packages
    // hand-roll is exactly OnDuplicate::Supersede once the entry has a key.
    app(PipelineStrategiesRegistry::class)
        ->register('widget-attributes-strategy', 'Schemastud\Frame\Strategies\WidgetAttributesStrategy');

    $entries = config('fixture.strategies');

    expect($entries)->toHaveCount(5)
        ->and($entries[3])->toBe('Schemastud\Frame\Strategies\WidgetAttributesStrategy');
});

it('refuses to invent a key for a list shape unless the owner says where one comes from', function () {
    config(['fixture.client.sources' => ['App\Sources\TenantSource']]);

    app(RealmSourcesRegistry::class)->keys();
})->throws(InvalidArgumentException::class, 'which is a LIST');

// ---------------------------------------------------------------------------------------------
// It is a registry like any other: index, registrars, authorization.
// ---------------------------------------------------------------------------------------------

it('describes into the index and routes by its declared root', function () {
    config(['fixture.strategies' => fiveRegistrants()]);

    $registry = app(PipelineStrategiesRegistry::class);
    $index = app(RegistryIndex::class)->describe($registry, $registry);

    expect($index->resolve('fixture.strategies'))->toBe($registry)
        ->and($index->routeTo('fixture.strategies.keyword-attributes-strategy'))->toBe($registry);
});

it('is fillable by a registrar, whose writes land in config', function () {
    config(['fixture.client.sources' => []]);

    $registry = app(RealmSourcesRegistry::class);
    $registry->attach(new ConfigRegistrar(
        ['defaults' => 'App\Sources\TenantSource'],
        'host.client.sources',
    ));

    expect(config('fixture.client.sources'))->toBe(['defaults' => 'App\Sources\TenantSource'])
        ->and($registry->registrars())->toHaveCount(1)
        ->and($registry->registrars()[0]->source())->toBe('config host.client.sources');
});

it('filters through an installed authorizer, and unfiltered() escapes it', function () {
    config(['fixture.client.sources' => ['defaults' => 'App\Sources\TenantSource']]);

    $registry = app(RealmSourcesRegistry::class);

    // Every entry a ConfigRegistry projects is ungated — a config array has nowhere to declare an
    // ability per slot — so an authorizer that denies everything still narrows nothing. That is
    // ticket 09 D2's short-circuit, and it holds here by construction.
    $registry->authorizeWith(new class implements Rushing\Popcorn\Registries\Authorizer
    {
        public function allows(string $ability, Rushing\Popcorn\Registries\RegistryKey $key): bool
        {
            return false;
        }
    });

    expect($registry->has('defaults'))->toBeTrue()
        ->and($registry->unfiltered()->has('defaults'))->toBeTrue();
});

it('is not Forgettable and does not record supersession — the storage holds values, not history', function () {
    $registry = app(RealmSourcesRegistry::class);

    expect($registry)->not->toBeInstanceOf(Rushing\Popcorn\Registries\Forgettable::class)
        ->and($registry)->not->toBeInstanceOf(Rushing\Popcorn\Registries\RecordsSupersession::class);
});

it('derives keys the same way Key::fromClass does, so a rekey moves nothing by accident', function () {
    expect((string) Key::fromClass('Schemastud\DataSchemas\Strategies\ValidationAttributeStrategy'))
        ->toBe('validation-attribute-strategy');
});
