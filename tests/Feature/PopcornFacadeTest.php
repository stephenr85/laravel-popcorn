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

it('still dispatches invoke($uri, $envelope), forwarded through the manager', function () {
    app(InvocableRegistry::class)->register(
        fakeInvocable('greet', fn (array $input) => ['hello' => $input['name']])
    );

    expect(Popcorn::invoke('greet', ['name' => 'world']))
        ->toBe(['hello' => 'world']);
});

it('is fakeable via a container swap', function () {
    $fake = new class(app(RegistryIndex::class), app(InvocableRegistry::class)) extends PopcornManager
    {
        public function invoke(string $uri, array $envelope): array
        {
            return ['faked' => $uri];
        }
    };

    Popcorn::swap($fake);

    expect(Popcorn::getFacadeRoot())->toBe($fake)
        ->and(Popcorn::invoke('anything', []))->toBe(['faked' => 'anything']);
});
