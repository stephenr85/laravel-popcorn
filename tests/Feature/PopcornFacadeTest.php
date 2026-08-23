<?php

use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;
use Rushing\Popcorn\InvocableRegistry;
use Rushing\Popcorn\Laravel\Facades\Popcorn;
use Rushing\Popcorn\Laravel\PopcornManager;
use Rushing\Popcorn\Registries\RegistryIndex;

function fakeInvocable(string $name, callable $fn): Invocable
{
    return new class($name, $fn) implements Invocable
    {
        public function __construct(private string $theName, private $fn) {}

        public function name(): string
        {
            return $this->theName;
        }

        public function binding(): Binding
        {
            return Binding::Local;
        }

        public function invoke(array $input): array
        {
            return ($this->fn)($input);
        }
    };
}

it('resolves the PopcornManager as its facade accessor', function () {
    // The accessor moved off the kernel type in registry-kernel ticket 20: a Laravel-side affordance
    // cannot hang off a package declaring `mustNotRequire: illuminate/*`.
    expect(Popcorn::getFacadeRoot())
        ->toBeInstanceOf(PopcornManager::class)
        ->toBe(app(PopcornManager::class));
});

it('reaches an invocable through the registry door, now that the forward is gone', function () {
    // Ticket 20 parked `Popcorn::invoke($uri, …)` here for ticket 30 to collapse. It collapsed by
    // DELETION rather than re-pointing — its live callers pass a json-ns URI, which is a foreign key and
    // therefore unroutable through the index (20 D3). The round trip is kept, through the owner's door.
    app(InvocableRegistry::class)->register(
        fakeInvocable('greet', fn (array $input) => ['hello' => $input['name']])
    );

    expect(Popcorn::registry('popcorn.invocables'))->toBeInstanceOf(InvocableRegistry::class)
        ->and(Popcorn::registry('popcorn.invocables')->invoke('greet', ['name' => 'world']))
        ->toBe(['hello' => 'world']);
});

it('routes a bare invocable key through the index like any other registry entry', function () {
    app(InvocableRegistry::class)->register(fakeInvocable('greet', fn () => []));

    // Keys are absolute out (20 D2), so an invocable is addressable in the global keyspace — which is
    // exactly what a foreign-keyed json-ns handler is NOT.
    expect(Popcorn::pop('popcorn.invocables.greet'))->toBeInstanceOf(Invocable::class)
        ->and(Popcorn::tryPop('popcorn.invocables.absent'))->toBeNull();
});

it('is fakeable via a container swap', function () {
    $fake = new class(app(RegistryIndex::class)) extends PopcornManager
    {
        public function pop(Rushing\Popcorn\Registries\RegistryKey|string $key): mixed
        {
            return ['faked' => (string) $key];
        }
    };

    Popcorn::swap($fake);

    expect(Popcorn::getFacadeRoot())->toBe($fake)
        ->and(Popcorn::pop('anything'))->toBe(['faked' => 'anything']);
});
