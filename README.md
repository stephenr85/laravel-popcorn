# laravel-popcorn

A tiny kernel for **invocable capabilities** and **strategy ladders**. One named
contract, three ways to answer it — local PHP, an MCP tool, or a webhook —
swappable without callers noticing. And a self-validating, self-demoting strategy
ladder for the times a capability has several ways to try.

It is deliberately small and framework-light: a handful of contracts plus a
registry. Engines (compliance, content, knowledge) reach for the same primitive
for extraction, reconciliation, tool dispatch, and tenant overrides.

## Invocables

```php
use Rushing\Popcorn\InvocableRegistry;
use Rushing\Popcorn\Invocables\LocalInvocable;
use Rushing\Popcorn\Invocables\RemoteInvocable;
use Rushing\Popcorn\Binding;

$registry = (new InvocableRegistry)
    ->register(new LocalInvocable('tag-ingredient', fn (array $in) => ['concept' => match_it($in)]));

// A tenant overrides the same capability with their own webhook — callers unchanged.
$registry->register(new RemoteInvocable('tag-ingredient', Binding::Webhook, $httpTransport, 'https://tenant.example/tag'));

$registry->invoke('tag-ingredient', ['raw' => 'Tirzepatide 10mg']);
```

The transport for a `RemoteInvocable` is injected (`fn(string $name, array $input): array`),
so the kernel needs no HTTP or MCP client of its own.

## Strategy ladders

```php
use Rushing\Popcorn\Strategy\StrategyLadder;

$ladder = new StrategyLadder($exactMatch, $embeddingSimilarity, $reviewerConfirmed);

// Strongest rung first; weaker rungs are tried only when stronger ones abstain
// or fall short. Null means every rung declined — the reviewer floor.
$result = $ladder->resolve($input, acceptAbove: 0.7);
```

A rung returns a `StrategyResult` it can stand behind, or `null` to step aside.
That is the self-validate-and-demote discipline: a strong rung that can't be sure
defers rather than guessing.

## Tests

```bash
composer install
vendor/bin/pest
```
