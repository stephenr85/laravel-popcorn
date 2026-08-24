<?php

use Rushing\Popcorn\Laravel\Facades\Popcorn;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Tests\Fixtures\NamespaceUriKey;

/**
 * `popcorn:keys` — the entry-level read `popcorn:registries` deliberately does not do (registry-kernel
 * ticket 13 D10), and the second consumer that earns `Popcorn::suggest()` its place on the manager.
 */
it('lists a registry\'s live keys at or under the prefix', function () {
    registryAt('beam.realm', ['tenant' => 'a', 'user' => 'b', 'overlays.article' => 'c']);

    $this->artisan('popcorn:keys', ['prefix' => 'beam.realm'])
        ->expectsOutput('beam.realm.tenant')
        ->expectsOutput('beam.realm.user')
        ->expectsOutput('beam.realm.overlays.article')
        ->assertSuccessful();
});

it('narrows to a deeper prefix, segment-wise', function () {
    registryAt('beam.realm', ['tenant' => 'a', 'overlays.article' => 'b']);
    registryAt('beam.realms', ['tenant' => 'c']);

    $this->artisan('popcorn:keys', ['prefix' => 'beam.realm.overlays', '--json' => true])
        ->assertSuccessful();

    // `beam.realms` is a string prefix match for `beam.realm` and is not under it, so it must not
    // appear even though the two registries sit side by side in the index.
    $this->artisan('popcorn:keys', ['prefix' => 'beam.realm'])
        ->doesntExpectOutput('beam.realms.tenant')
        ->expectsOutput('beam.realm.tenant')
        ->assertSuccessful();
});

it('reads unfiltered, and needs the SECOND unfiltered call to do it', function () {
    // `RegistryIndex::unfiltered()` unfilters which registries you can see and hands back the same live
    // singletons still carrying the pushed authorizer (ticket 17 D6). A catalogue is worthless if it
    // silently omits rows, so this command escapes on the registry too — and this test is what fails
    // if that second call is ever dropped as redundant.
    registryAt('beam.realm', ['tenant' => 'a'], ['payroll' => ['b', 'view-payroll']]);
    Popcorn::authorizeWith(denyEverything());

    expect(Popcorn::tryPop('beam.realm.payroll'))->toBeNull();

    $this->artisan('popcorn:keys', ['prefix' => 'beam.realm'])
        ->expectsOutput('beam.realm.payroll')
        ->assertSuccessful();
});

it('emits the keys as JSON under --json', function () {
    registryAt('beam.realm', ['tenant' => 'a', 'user' => 'b']);

    $this->artisan('popcorn:keys', ['prefix' => 'beam.realm', '--json' => true])
        ->expectsOutput(json_encode([
            'prefix' => 'beam.realm',
            'keys' => ['beam.realm.tenant', 'beam.realm.user'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
        ->assertSuccessful();
});

it('fails an illegal prefix with the grammar message, before touching the index', function () {
    $this->artisan('popcorn:keys', ['prefix' => 'Beam.Realm'])
        ->assertFailed();
});

it('fails when no registry claims the prefix, and suggests through the shared helper', function () {
    registryAt('beam.realm', ['tenant' => 'a']);

    $this->artisan('popcorn:keys', ['prefix' => 'nothing.claims.this'])
        ->assertFailed();
});

it('lists a foreign-keyed registry only at its own root, and renders each key as its owner does', function () {
    // A foreign key is never stamped, so it is under nothing (20 D3) — there is no sub-prefix to
    // narrow by, and its rendering is the owner's rather than a dotted address.
    $store = new BasicRegistry(new IsRegistry(
        root: 'jsonns.namespaces',
        of: 'namespaces by URI',
        arity: RegistryArity::PickOne,
    ));
    $store->register(NamespaceUriKey::of('https://schemastud.dev/ns/grounding/2'), 'the schema', by: 'tests');
    app(RegistryIndex::class)->describe($store);

    $this->artisan('popcorn:keys', ['prefix' => 'jsonns.namespaces'])
        ->expectsOutput('https://schemastud.dev/ns/grounding/2')
        ->assertSuccessful();

    $this->artisan('popcorn:keys', ['prefix' => 'jsonns.namespaces.grounding'])
        ->doesntExpectOutput('https://schemastud.dev/ns/grounding/2')
        ->assertSuccessful();
});
