<?php

use Rushing\Popcorn\Binding;
use Rushing\Popcorn\InvocableRegistry;
use Rushing\Popcorn\Invocables\LocalInvocable;
use Rushing\Popcorn\Invocables\RemoteInvocable;

it('registers, finds, and invokes a local capability', function () {
    $registry = (new InvocableRegistry)->register(
        new LocalInvocable('echo', fn (array $in) => ['out' => $in['value'] ?? null]),
    );

    expect($registry->has('echo'))->toBeTrue()
        ->and($registry->names())->toBe(['echo'])
        ->and($registry->invoke('echo', ['value' => 42]))->toBe(['out' => 42]);
});

it('overrides a capability when re-registered under the same name', function () {
    $registry = (new InvocableRegistry)
        ->register(new LocalInvocable('tag', fn () => ['by' => 'local']))
        ->register(new RemoteInvocable('tag', Binding::Webhook, fn (string $n, array $in) => ['by' => 'webhook']));

    expect($registry->get('tag')->binding())->toBe(Binding::Webhook)
        ->and($registry->invoke('tag', []))->toBe(['by' => 'webhook']);
});

it('routes a remote invocable through its injected transport', function () {
    $seen = [];
    $remote = new RemoteInvocable('extract', Binding::Mcp, function (string $name, array $in) use (&$seen) {
        $seen = [$name, $in];

        return ['ok' => true];
    });

    expect($remote->invoke(['x' => 1]))->toBe(['ok' => true])
        ->and($seen)->toBe(['extract', ['x' => 1]]);
});

it('throws for an unknown capability', function () {
    (new InvocableRegistry)->get('missing');
})->throws(InvalidArgumentException::class);

it('rejects a remote invocable bound as local', function () {
    new RemoteInvocable('x', Binding::Local, fn () => []);
})->throws(InvalidArgumentException::class);
