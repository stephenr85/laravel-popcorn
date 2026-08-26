<?php

use Illuminate\Support\Facades\Validator;
use Rushing\Popcorn\Laravel\Facades\Popcorn;
use Rushing\Popcorn\Laravel\Rules\ExistsInRegistry;
use Rushing\Popcorn\Registries\AbsoluteUriKey;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\RelativeUriKey;
use Rushing\Popcorn\Tests\Fixtures\NamespaceUriKey;

/**
 * The estate's first package-shipped validation rule (registry-kernel tickets 13 and 32): one prefix,
 * no glob, `matches()` semantics rather than a second code path, two messages in a fixed order, always
 * filtered, and a developer error where the mistake is the developer's.
 */
function failureFor(mixed $value, string $prefix = 'beam.realm'): ?string
{
    $validator = Validator::make(['realm' => $value], ['realm' => [new ExistsInRegistry($prefix)]]);

    return $validator->errors()->first('realm') ?: null;
}

it('passes a live key at or under the prefix', function () {
    registryAt('beam.realm', ['tenant' => 'the tenant realm', 'user' => 'the user realm']);

    expect(failureFor('beam.realm.tenant'))->toBeNull()
        ->and(failureFor('beam.realm.user'))->toBeNull();
});

it('takes a deeper prefix than the root, and refuses what sits outside it', function () {
    registryAt('beam.realm', ['overlays.article' => 'a', 'overlays.page' => 'b', 'tenant' => 'c']);

    expect(failureFor('beam.realm.overlays.article', 'beam.realm.overlays'))->toBeNull()
        // Registered, visible, and in the same registry — but not under the prefix this field accepts.
        ->and(failureFor('beam.realm.tenant', 'beam.realm.overlays'))->not->toBeNull();
});

it('is segment-wise, so a string prefix that is not a key prefix does not validate', function () {
    // The fork detector. `beam.realms.tenant` is a live, resolvable key in the estate AND a string
    // prefix match for `beam.realm` — so a rule that reached for str_starts_with() instead of the
    // read path's own comparison would accept a value the resolver would then route elsewhere. This
    // is 13 §4's load-bearing constraint, spelled as a test that fails the moment the paths fork.
    registryAt('beam.realm', ['tenant' => 'the realm']);
    registryAt('beam.realms', ['tenant' => 'the plural']);

    expect(Popcorn::pop('beam.realms.tenant'))->toBe('the plural')
        ->and(failureFor('beam.realms.tenant'))->not->toBeNull();
});

it('checks legality first, with its own message, and touches no registry doing it', function () {
    // The prefix names a registry that has NOT described itself, which is a developer error the rule
    // throws on — so if this returns a validation message rather than raising, the legality check
    // demonstrably short-circuited before any lookup (13 D8).
    expect(failureFor('Beam.Realm', 'nothing.claims.this'))
        ->toContain('not a legal registry key')
        ->not->toContain('not a registered key');
});

it('gives a miss a different message from a declaration error, carrying suggestions', function () {
    registryAt('beam.realm', ['tenant' => 'a', 'user' => 'b']);

    expect(failureFor('beam.realm.tenat'))
        ->toContain('not a registered key under `beam.realm`')
        ->toContain('`beam.realm.tenant`');
});

it('suggests only keys under its own prefix', function () {
    registryAt('beam.realm', ['tenant' => 'a']);
    registryAt('graph.stores', ['tenant' => 'b']);

    // `graph.stores.tenant` is a near neighbour by levenshtein and belongs to a keyspace this field
    // does not accept; naming it would be noise at best and a tour of the estate at worst.
    expect(failureFor('graph.stores.tenat'))
        ->toContain('not a registered key under `beam.realm`')
        ->not->toContain('graph.stores');
});

it('fails a hidden key with the message an absent key gets, byte for byte', function () {
    // Same input, two worlds: in the first `beam.realm.payroll` was never registered; in the second it
    // is registered and gated away from this caller. MissReason::Filtered already renders as Absent,
    // and a rule whose ERROR MESSAGE distinguished them would be the existence oracle the whole
    // authorization seam exists to close (13 D3).
    registryAt('beam.realm', ['tenant' => 'a']);
    $absent = failureFor('beam.realm.payroll');

    app(RegistryIndex::class)->forget('beam.realm');
    registryAt('beam.realm', ['tenant' => 'a'], ['payroll' => ['b', 'view-payroll']]);
    Popcorn::authorizeWith(denyEverything());
    $hidden = failureFor('beam.realm.payroll');

    expect($hidden)->not->toBeNull()->toBe($absent);
});

it('never suggests a key the caller cannot see', function () {
    registryAt('beam.realm', ['tenant' => 'a'], ['payrol' => ['b', 'view-payroll']]);

    // One character out, so it is the nearest neighbour by a distance of 1 — and computing the
    // suggestion over the unfiltered list would undo the filter through the error message.
    expect(failureFor('beam.realm.payroll'))->toContain('`beam.realm.payrol`');

    Popcorn::authorizeWith(denyEverything());

    expect(failureFor('beam.realm.payroll'))
        ->not->toBeNull()
        ->not->toContain('payrol`');
});

it('raises a developer error rather than failing the user when nothing claims the prefix', function () {
    // UnregisteredRegistry is a SIBLING of RegistryMiss, not a subclass, and the two failures have
    // different fixes: this one is usually a provider that did not describe its registry, which is
    // not the fault of whoever filled in the form.
    expect(fn () => failureFor('beam.realm.tenant', 'nothing.claims.this'))
        ->toThrow(InvalidArgumentException::class, 'No registry claims `nothing.claims.this`');
});

it('raises a developer error rather than failing the user on an unaddressable registry', function () {
    // Ticket 11's standing rule: a green suite against `Key` proves nothing about the seam, because
    // `Key` is the one implementation that round-trips. A key the kernel cannot address is never
    // stamped with a root (20 D3, narrowed by 64), so a prefix cannot name its entries and comparing
    // renderings would compare the wrong thing — a validation failure here would blame the user for
    // the developer's mistake (13 D4).
    $store = new BasicRegistry(new IsRegistry(
        root: 'jsonns.namespaces',
        of: 'namespaces by URI',
        arity: RegistryArity::PickOne,
    ));
    $store->register(NamespaceUriKey::of('https://schemastud.dev/ns/grounding/2'), 'the schema', by: 'tests');
    app(RegistryIndex::class)->describe($store);

    expect(fn () => failureFor('jsonns.namespaces.grounding', 'jsonns.namespaces'))
        ->toThrow(InvalidArgumentException::class, 'which the kernel cannot address');
});

it('refuses an unaddressable key as the prefix itself, at construction', function () {
    expect(fn () => new ExistsInRegistry(NamespaceUriKey::of('https://schemastud.dev/ns/grounding/2')))
        ->toThrow(InvalidArgumentException::class, 'whose keys the kernel cannot address');
});

it('refuses an AbsoluteUriKey prefix too, because kernel-owned is not the test', function () {
    // The trap in the narrowing: "the kernel ships it" is NOT the criterion. AbsoluteUriKey is the
    // kernel's own and still declines root-stamping, so its entries have no address for a prefix to
    // name — exactly as a consumer's own key type does. What the rule asks is whether the key comes
    // back addressable, not who wrote the class (ticket 64).
    expect(fn () => new ExistsInRegistry(AbsoluteUriKey::of('https://app.splicewire.com/json-schemas/grounding')))
        ->toThrow(InvalidArgumentException::class, 'whose keys the kernel cannot address');
});

it('ACCEPTS a slash-spelled prefix and the registry behind it, which is what 64 changed', function () {
    // The behavioural half of the narrowing, and the reason it needs a test rather than a docblock:
    // a RelativeUriKey is neither foreign nor canonical. It parses to a dotted address at the door,
    // so both the old `instanceof Key` gates — the prefix check and the keys() scan in assertUsable()
    // — used to refuse a registry that is fully addressable. Nothing about the failure was visible
    // from the owning package's own suite.
    $store = new BasicRegistry(new IsRegistry(
        root: 'nav.kinds',
        of: 'nav kinds',
        arity: RegistryArity::PickOne,
    ));
    $store->register(RelativeUriKey::of('nav/link'), 'the link kind', by: 'tests');
    app(RegistryIndex::class)->describe($store);

    expect(new ExistsInRegistry(RelativeUriKey::of('nav/kinds')))->toBeInstanceOf(ExistsInRegistry::class)
        ->and(failureFor('nav.kinds.nav.link', 'nav.kinds'))->toBeNull()
        ->and(failureFor('nav.kinds.nav.absent', 'nav.kinds'))->not->toBeNull();
});

it('accepts no glob, because there is no second form', function () {
    // `*` is the mini-language ticket 05 deleted; it is not a legal segment, so the prefix throws on
    // the way in rather than being quietly read as "everything".
    expect(fn () => new ExistsInRegistry('beam.realm.*'))
        ->toThrow(Rushing\Popcorn\Registries\Exceptions\InvalidRegistryKey::class);
});

it('rejects a non-string value as a declaration error rather than crashing', function () {
    registryAt('beam.realm', ['tenant' => 'a']);

    expect(failureFor(['beam.realm.tenant']))->toContain('not a legal registry key');
});
