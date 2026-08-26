<?php

use Rushing\Popcorn\Laravel\Facades\Popcorn;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\CarriesDeclaration;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Tests\Fixtures\NamespaceUriKey;

/**
 * `popcorn:keys` — the entry-level read `popcorn:registries` deliberately does not do (registry-kernel
 * ticket 13 D10), and the second consumer that earns `Popcorn::suggest()` its place on the manager.
 */
it('lists a registry\'s live keys at or under the prefix, WITH their registrants', function () {
    // Re-grounded by registry-kernel ticket 48: 13 D10 chartered this command as "keys with their
    // registrants" and ticket 32 shipped only the first half, because nothing could read `by` back for
    // a live entry. `RecordsRegistrants` landed, so the table is the output now — wherever anyone
    // actually named a registrant.
    registryAt('beam.realm', ['tenant' => 'a', 'user' => 'b', 'overlays.article' => 'c']);

    $this->artisan('popcorn:keys', ['prefix' => 'beam.realm'])
        ->expectsTable(['Key', 'Registered by'], [
            ['beam.realm.tenant', 'tests'],
            ['beam.realm.user', 'tests'],
            ['beam.realm.overlays.article', 'tests'],
        ])
        ->assertSuccessful();
});

it('keeps the pipe-friendly one-key-per-line output where nobody named a registrant', function () {
    // The majority case: 29 D2 measured 13 of 38 live entries carrying `by` at all. A table of dashes
    // would be worse than the lines it replaced, and would break every pipe into grep for no gain.
    $store = new BasicRegistry(new IsRegistry(
        root: 'beam.anon',
        of: 'test entries',
        arity: RegistryArity::PickOne,
    ));
    $store->register('tenant', 'a');
    $store->register('user', 'b');
    app(RegistryIndex::class)->describe($store);

    $this->artisan('popcorn:keys', ['prefix' => 'beam.anon'])
        ->expectsOutput('beam.anon.tenant')
        ->expectsOutput('beam.anon.user')
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
        ->expectsTable(['Key', 'Registered by'], [
            ['beam.realm.tenant', 'tests'],
            ['beam.realm.overlays.article', 'tests'],
        ])
        ->assertSuccessful();
});

it('reads unfiltered — a catalogue that silently omits rows is worthless', function () {
    // The registry's own `unfiltered()` is what this command depends on. It used to be the SECOND of
    // two calls, because `RegistryIndex::unfiltered()` escaped one level (17 D6); ticket 45 made the
    // index deep, so the index half is no longer load-bearing here. This test is what fails if the
    // registry-level escape is ever dropped as redundant — and the registrant column must escape with
    // it, or a visible key would print a dash for a registrant it has.
    registryAt('beam.realm', ['tenant' => 'a'], ['payroll' => ['b', 'view-payroll']]);
    Popcorn::authorizeWith(denyEverything());

    expect(Popcorn::tryPop('beam.realm.payroll'))->toBeNull();

    $this->artisan('popcorn:keys', ['prefix' => 'beam.realm'])
        ->expectsTable(['Key', 'Registered by'], [
            ['beam.realm.tenant', 'tests'],
            ['beam.realm.payroll', 'tests'],
        ])
        ->assertSuccessful();
});

it('emits the keys as JSON under --json', function () {
    registryAt('beam.realm', ['tenant' => 'a', 'user' => 'b']);

    $this->artisan('popcorn:keys', ['prefix' => 'beam.realm', '--json' => true])
        ->expectsOutput(json_encode([
            'prefix' => 'beam.realm',
            'keys' => ['beam.realm.tenant', 'beam.realm.user'],
            // Additive beside `keys` rather than reshaping it into objects, so a reader that already
            // parses this output cannot break (ticket 48).
            'registrants' => ['beam.realm.tenant' => 'tests', 'beam.realm.user' => 'tests'],
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
        ->expectsTable(['Key', 'Registered by'], [
            ['https://schemastud.dev/ns/grounding/2', 'tests'],
        ])
        ->assertSuccessful();

    $this->artisan('popcorn:keys', ['prefix' => 'jsonns.namespaces.grounding'])
        ->doesntExpectOutput('https://schemastud.dev/ns/grounding/2')
        ->assertSuccessful();
});

// ---------------------------------------------------------------------------------------------
// --supersessions — the reader registry-kernel ticket 48 was opened to find
// ---------------------------------------------------------------------------------------------

it('reports what was overwritten under the prefix, with who put the displaced entry there', function () {
    $store = registryAt('beam.realm', ['tenant' => 'the shipped default']);
    $store->register('tenant', 'the host override', by: 'acme/host-app');
    app(RegistryIndex::class)->describe($store);

    $this->artisan('popcorn:keys', ['prefix' => 'beam.realm', '--supersessions' => true])
        ->expectsTable(['Key', 'Displaced entry registered by', 'Sequence'], [
            ['beam.realm.tenant', 'tests', '0'],
        ])
        ->assertSuccessful();
});

it('says nothing was overwritten rather than printing an empty table', function () {
    registryAt('beam.realm', ['tenant' => 'a']);

    $this->artisan('popcorn:keys', ['prefix' => 'beam.realm', '--supersessions' => true])
        ->assertSuccessful();
});

it('distinguishes "no history kept" from "nothing was overwritten" — the ConfigRegistry hole, said out loud', function () {
    // A registry that does not implement RecordsSupersession has nothing to report, and that is not the
    // same claim as a clean run. 19 D5's measurement is why this warns instead of printing an empty
    // result: the config-fed registrants bypass register() entirely, so OnDuplicate never runs and the
    // record dies with the projection.
    $store = new class(new IsRegistry(root: 'beam.historyless', of: 'test entries', arity: RegistryArity::PickOne)) implements CarriesDeclaration, Registry
    {
        private BasicRegistry $entries;

        // Declares INLINE through the seam registry-kernel 59 B1 landed, which is what lets a store
        // holding no class attribute reach the index at all.
        public function __construct(private IsRegistry $declaration)
        {
            $this->entries = new BasicRegistry($declaration);
            $this->entries->register('one', 'a', by: 'tests');
        }

        public function declaration(): IsRegistry
        {
            return $this->declaration;
        }

        public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static
        {
            $this->entries->register($key, $entry, $by, $ability);

            return $this;
        }

        public function has(RegistryKey|string $key): bool
        {
            return $this->entries->has($key);
        }

        public function resolve(RegistryKey|string $key): mixed
        {
            return $this->entries->resolve($key);
        }

        public function tryResolve(RegistryKey|string $key): mixed
        {
            return $this->entries->tryResolve($key);
        }

        public function matches(RegistryKey|string $key): array
        {
            return $this->entries->matches($key);
        }

        public function keys(): array
        {
            return $this->entries->keys();
        }

        public function unfiltered(): Registry
        {
            return $this;
        }
    };

    app(RegistryIndex::class)->describe($store);

    $this->artisan('popcorn:keys', ['prefix' => 'beam.historyless', '--supersessions' => true])
        ->assertSuccessful();
});
