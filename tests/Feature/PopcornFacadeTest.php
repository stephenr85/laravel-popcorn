<?php

use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;
use Rushing\Popcorn\InvocableRegistry;
use Rushing\Popcorn\Laravel\Facades\Popcorn;

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

it('resolves the InvocableRegistry singleton as its facade accessor', function () {
    expect(Popcorn::getFacadeRoot())
        ->toBeInstanceOf(InvocableRegistry::class)
        ->toBe(app(InvocableRegistry::class));
});

it('dispatches invoke($uri, $envelope) through the InvocableRegistry', function () {
    app(InvocableRegistry::class)->register(
        fakeInvocable('greet', fn (array $input) => ['hello' => $input['name']])
    );

    expect(Popcorn::invoke('greet', ['name' => 'world']))
        ->toBe(['hello' => 'world']);
});

it('is fakeable via a container swap', function () {
    $fake = new class extends InvocableRegistry
    {
        public function invoke(string $name, array $input): array
        {
            return ['faked' => $name];
        }
    };

    Popcorn::swap($fake);

    expect(Popcorn::getFacadeRoot())->toBe($fake)
        ->and(Popcorn::invoke('anything', []))->toBe(['faked' => 'anything']);
});
