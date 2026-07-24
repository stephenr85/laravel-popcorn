# laravel-popcorn

A tiny kernel for **invocable capabilities** and **strategy ladders**. One named
contract, several ways to answer it — local PHP, an MCP tool, a webhook, or a
subprocess — swappable without callers noticing. Plus a transparent caching
decorator, and a self-validating, self-demoting strategy ladder for the times a
capability has several ways to try.

It is deliberately small and framework-light: a handful of contracts plus a
registry. Engines (compliance, content, knowledge) and packages like
[`rushing/laravel-prism-plus`](https://github.com/stephenr85/laravel-prism-plus)
reach for the same primitive for extraction, reconciliation, tool dispatch,
tenant overrides, and capability registries.

## Installation

```bash
composer require rushing/laravel-popcorn
```

## Invocables

An `Invocable` is a named, transport-agnostic capability: **array in, array out**.
The contract says nothing about *where* the work happens — a `Binding` does. That
array boundary is the whole point: a schema authored locally and one codegen'd
from a remote tool converge on one read path.

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

**Registering under an existing name overrides it** — the seam where a host swaps a
local default for a tenant's webhook without callers changing. The rest of the
registry surface:

```php
$registry->has('tag-ingredient');    // bool
$registry->get('tag-ingredient');    // the Invocable (throws if absent)
$registry->names();                  // ['tag-ingredient', …]
$registry->forget('tag-ingredient'); // remove it — the teardown half of a per-tenant overlay
```

`forget()` is the reason a shared worker can project tenant-scoped invocables on a
tenant switch and drop them on revert, so nothing bleeds across tenants.

### The four bindings

| Invocable | Binding | Answers with |
|---|---|---|
| `LocalInvocable` | `Local` | an in-process PHP closure |
| `RemoteInvocable` | `Mcp` | an MCP tool (transport injected) |
| `RemoteInvocable` | `Webhook` | an HTTP webhook (transport injected) |
| `ProcessInvocable` | — | a subprocess that returns JSON over stdout |

The transport for a `RemoteInvocable` is injected (`fn(string $name, array $input): array`),
so the kernel depends on no HTTP or MCP client of its own — the host plugs in whichever it uses.

## Caching

`CachedInvocable` wraps **any** invocable and memoizes its array-out result per a
key you derive from the array-in input. Because it *is* an `Invocable`, caching is
transparent to callers and composes with any binding — a local handler, an MCP
tool, or a webhook are all cached the same way ("remote" is a binding; "cached" is
a decorator):

```php
use Rushing\Popcorn\Invocables\CachedInvocable;

$registry->register(new CachedInvocable(
    $inner,                                   // any Invocable
    cache: cache()->store('redis'),
    keyFor: fn (array $in) => md5(json_encode($in)),
    ttl: 3600,                                // seconds; null = cache forever (frozen snapshot)
));
```

A `null` TTL caches with no expiry (a frozen snapshot for identical inputs); a
positive TTL expires the entry (a memoized call shared across callers).

## Registry of registries

Because an `InvocableRegistry` is itself just an object, you nest them to key a
**capability** to a registry of **providers** — two plain string-keyed levels,
which is exactly how prism-plus models `rerank`/`video` → `voyageai`/`fal`:

```php
// capability => registry => provider => Invocable
$capabilities = [];
$capabilities['rerank'] = (new InvocableRegistry)
    ->register(new LocalInvocable('voyageai', $voyageDriver))
    ->register(new LocalInvocable('cohere', $cohereDriver));

$capabilities['rerank']->invoke('voyageai', $input);
```

Adding a provider is a `register()` call; adding a whole capability is a new key.

## Strategy ladders

```php
use Rushing\Popcorn\Strategy\StrategyLadder;

$ladder = new StrategyLadder($exactMatch, $embeddingSimilarity, $reviewerConfirmed);

// Strongest rung first; weaker rungs are tried only when stronger ones abstain
// or fall short. Null means every rung declined — the caller's reviewer floor.
$result = $ladder->resolve($input, acceptAbove: 0.7);
```

A rung (`Strategy`) returns a `StrategyResult` it can stand behind (`value`,
`confidence`, `strategy`), or `null` to step aside. That is the
self-validate-and-demote discipline: a strong rung that can't be sure defers
rather than guessing. `$ladder->rungs()` lists the rung names, strongest-first.

## Tests

```bash
composer install
vendor/bin/pest
```

## Licence

MIT
