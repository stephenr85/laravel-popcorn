<?php

use Rushing\Popcorn\InvocableRegistry;

it('registers the invocable registry as a singleton', function () {
    expect(app(InvocableRegistry::class))
        ->toBeInstanceOf(InvocableRegistry::class)
        ->and(app(InvocableRegistry::class))->toBe(app(InvocableRegistry::class));
});
