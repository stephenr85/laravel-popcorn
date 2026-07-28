<?php

use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Limits;
use Rushing\Popcorn\Runner\Net;

it('has a deny-by-default floor', function () {
    $g = Grant::none();

    expect($g->isFloor())->toBeTrue()
        ->and($g->net)->toBe(Net::None)
        ->and($g->pathsRo)->toBe([])
        ->and($g->env)->toBe([]);
});

it('parses paths, net, env and limits from an array', function () {
    $g = Grant::fromArray([
        'paths' => ['ro' => ['/pkg'], 'rw' => ['/tmp']],
        'net' => 'scoped',
        'env' => ['A' => '1'],
        'limits' => ['wallMs' => 2000, 'memBytes' => 1024],
    ]);

    expect($g->pathsRo)->toBe(['/pkg'])
        ->and($g->pathsRw)->toBe(['/tmp'])
        ->and($g->net)->toBe(Net::Scoped)
        ->and($g->env)->toBe(['A' => '1'])
        ->and($g->limits->wallMs)->toBe(2000)
        ->and($g->isFloor())->toBeFalse();
});

it('intersect narrows and never widens — effective = requested ∩ policy', function () {
    $requested = new Grant(
        pathsRo: ['/pkg', '/etc'],
        pathsRw: ['/tmp'],
        net: Net::Open,
        env: ['A' => '1', 'B' => '2'],
        limits: new Limits(wallMs: 10000, memBytes: 500),
    );

    $policy = new Grant(
        pathsRo: ['/pkg'],          // /etc capped away
        pathsRw: ['/tmp', '/var'],
        net: Net::Scoped,           // ladder capped below Open
        env: ['A' => 'ignored'],    // only key A allowed through
        limits: new Limits(wallMs: 3000),
    );

    $effective = $requested->intersect($policy);

    expect($effective->pathsRo)->toBe(['/pkg'])
        ->and($effective->pathsRw)->toBe(['/tmp'])
        ->and($effective->net)->toBe(Net::Scoped)
        ->and($effective->env)->toBe(['A' => '1'])          // value from requested, key gated by policy
        ->and($effective->limits->wallMs)->toBe(3000)       // tighter bound wins
        ->and($effective->limits->memBytes)->toBe(500);     // policy unbounded ⇒ requested wins
});

it('cannot escalate past a floor policy', function () {
    $requested = new Grant(net: Net::Open, pathsRo: ['/anything']);

    expect($requested->intersect(Grant::none())->isFloor())->toBeTrue();
});

it('narrows the net ladder correctly', function () {
    expect(Net::Open->narrowest(Net::Scoped))->toBe(Net::Scoped)
        ->and(Net::Scoped->narrowest(Net::None))->toBe(Net::None)
        ->and(Net::None->allowsEgress())->toBeFalse()
        ->and(Net::Scoped->allowsEgress())->toBeTrue();
});
