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

**Registering under an existing name overrides it here**, because `InvocableRegistry`
declares the Supersede duplicate policy: the new entry replaces the old one in its place. That is
the seam where a host swaps a local default for a tenant's webhook without callers changing. Not
every registry behaves this way. Each declares its own policy: under Reject a duplicate key throws
`DuplicateRegistryKey` at registration, and under Admit both entries are kept and a single-entry
read of that key refuses as ambiguous. The rest of the registry surface:

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

## The Runner substrate — scoped, documented code execution

`ProcessInvocable` spawns a raw subprocess with **zero isolation**. The **Runner substrate** is its
disciplined successor: it turns *scoped, documented code execution* into a first-class primitive, so a
host can safely run code it did not author (a tenant's transform, a published community tool). Four
value objects behind one mechanism contract — **still array-in / array-out**, so the whole kernel above
composes over a sandboxed run unchanged:

| Noun | What it is |
|---|---|
| **`Manifest`** | *What* to run — `name`, `runtime` (`python@3.12`, `javy`, …), `entrypoint`, `files`. Canonically a `popcorn.json` file; loaded into an immutable VO. Optional publishing metadata (`input`/`output` schema, requested grants) a simple internal transform omits. |
| **`Grant`** | *What it's allowed* — `paths.ro/rw`, `net` (`none`/`scoped`/`open`), `env` allowlist, `limits`, `seccompProfile`. **Deny-by-default** (`Grant::none()` is the floor). A superset DTO that maps onto both bwrap and wasmtime. |
| **`Result`** | *What came back* — a **total** VO (every run returns one), a closed `Outcome` enum, bounded `rawOutput()`/`stderr()`, and compute telemetry. `->output()` decodes the array-out; `->throw()` maps the outcome to a typed `RunFailed`. |
| **`Runner`** | The executor: `run(Manifest, Grant, array $input): Result`. **One implementation per isolation-backend, never per language** — the runtime lives in the Manifest. |

```php
use Rushing\Popcorn\Invocables\RunnerInvocable;
use Rushing\Popcorn\Laravel\Runner\NullRunner;
use Rushing\Popcorn\Runner\{Manifest, Grant};

$manifest = Manifest::fromFile('/bundles/shape-payload/popcorn.json');
$grant    = Grant::none();                 // effective grant, resolved host-side (never a superset of the request)

// A RunnerInvocable IS an Invocable — cache it, ladder it, override it by name, all unchanged.
$registry->register(new RunnerInvocable('shape-payload', new NullRunner, $manifest, $grant));
$registry->invoke('shape-payload', ['raw' => '…']);   // → run(...)->throw()->output()
```

**Requested vs. effective grants** are the security core: a Manifest *requests* an honest upper bound;
the host resolves the **effective** grant per-invocation as `requested ∩ policy` (deny-by-default, never
a superset) and passes *that* to `run()`. The kernel consumes an effective `Grant`; it never sources one
— policy custody stays host-side.

The kernel ships **no** bwrap/wasmtime dependency. It ships the contract and one dependency-free Runner —
**`NullRunner`** (runs the argv unsandboxed, `sandboxed: false`, the CI-fast / pure-wiring path). Real
isolation lives in substrate packages: **`rushing/laravel-popcorn-bubble`** (bwrap, Linux) and
**`rushing/laravel-popcorn-wasm`** (wasmtime, everywhere incl. macOS). *Which* Runner answers is a
host-capability resolution.

> **`ProcessInvocable` is soft-deprecated.** It is exactly "a `RunnerInvocable` over `NullRunner` with an
> empty `Grant`, made concrete before the seam existed." It stays shipped and working (its live consumers
> are untouched); route all *new* execution work through `RunnerInvocable`.

## Caching

`CachedInvocable` wraps **any** invocable and memoizes its array-out result per a
key you derive from the array-in input. Because it *is* an `Invocable`, caching is
transparent to callers and composes with any binding — a local handler, an MCP
tool, or a webhook are all cached the same way ("remote" is a binding; "cached" is
a decorator):

```php
use Rushing\Popcorn\Laravel\Invocables\CachedInvocable;

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
use Rushing\Popcorn\Ladders\Ladder;

$ladder = new Ladder($exactMatch, $embeddingSimilarity, $reviewerConfirmed);

// Strongest rung first; weaker rungs are tried only when stronger ones abstain
// or fall short. Null means every rung declined — the caller's reviewer floor.
$result = $ladder->climb($input, acceptAbove: 0.7);
```

A rung (`Rung`) returns a `RungResult` it can stand behind (`value`,
`confidence`, `rung`), or `null` to step aside. That is the
self-validate-and-demote discipline: a strong rung that can't be sure defers
rather than guessing. `$ladder->rungs()` lists the rung names, strongest-first.

## Registry validation and enumeration

`Rushing\Popcorn\Laravel\Rules\ExistsInRegistry` validates visible, fully qualified keys
under a registry prefix. The same rule exposes `contains($value)` and `values()` for route
validation and schema generation:

```php
use Rushing\Popcorn\Laravel\Rules\ExistsInRegistry;
use Rushing\Popcorn\Registries\Key;

$rule = new ExistsInRegistry('app.formats', relative: true,
    where: fn (Key $key): bool => $key->isUnder('app.formats.document'));

$rules = ['format' => ['required', $rule]];
$choices = $rule->values(); // e.g. ['document.json', 'document.markdown']
```

`relative: true` accepts suffixes only; the default accepts fully qualified keys. The optional
predicate receives a canonical key after membership and visibility checks. It must be a read-only
predicate so validation and enumeration have the same result. Values are sorted and read fresh,
including nested registry owners; hidden entries never enter the enum, predicate or suggestions.
An empty vocabulary means no accepted values. A prefix without a registered owner raises a developer
error rather than returning an empty list. Generate documentation under its intended audience and
never share a cached actor-specific vocabulary across actors.

## Registries

This package declares no registry of its own. The registry kernel, `#[IsRegistry]` and
`InvocableRegistry` (root [`popcorn.invocables`](https://github.com/stephenr85/php-popcorn/blob/main/docs/registries/popcorn.invocables.mdx))
come from [`rushing/php-popcorn`](https://github.com/stephenr85/php-popcorn); this package binds
them into Laravel and adds the commands below. `php artisan popcorn:registries` lists the
registries a host has, and `php artisan popcorn:keys <prefix>` lists the keys under a prefix.

## Making a registry

```bash
php artisan popcorn:make:registry ReportFormatRegistry --key=app.report-formats
```

This writes `app/Registries/ReportFormatRegistry.php`. The class declares its root and duplicate
policy with `#[IsRegistry]`, implements `Gated` and `Registry`, holds a `BasicRegistry`, and forwards
the reads and the host's authorizer to it through php-popcorn's `ForwardsToRegistryStore` trait. You
add a description and your own methods.

`--key` is the root the registry owns, in dotted lowercase-kebab; an illegal key is refused. The
command then prints what is left to do, and does none of it:

- the docs page to write, `docs/registries/<root>.mdx`, and the
  `splicewire:beam:docs:registries:scaffold` line that writes it where `splicewire/laravel-beam-docs`
  is installed. That command is printed and never called, because this package cannot depend on beam;
- the singleton binding to add to a provider;
- the `popcorn:registries:cache` bake that describes the class into the index;
- the `splicewire:beam:registry-conformance --write` line, for hosts that run `splicewire/laravel-beam`.

The stub is `stubs/registry.stub`; a host overrides it by placing its own at the same path under its
base path.

## Documenting your registries

A package documents each registry it declares with one page, `docs/registries/<root>.mdx`, named
by the dotted root verbatim (`docs/registries/graph.stores.mdx`). A root whose page lives elsewhere,
or that is deliberately undocumented, is listed in `docs/registries/manifest.json` instead, as an
override path, an external URL, or an exemption with its reason. The package README then gets a
short Registries section that links each page; the facts about a registry (entry type, duplicate
policy, order) stay on its page, where they're generated from the declaration.

Creating the `docs/registries/` directory opts the package in. From then on one test keeps it
honest: every declared root must be covered, every page and manifest entry must name a root the
package declares, and each page's generated facts must match the declaration. Add it to your
suite, with a count of the roots you expect so a root that stops being scanned can't pass silently:

```php
use Rushing\Popcorn\Registries\Docs\RegistryDocsCheck;

it('documents every declared registry root', function () {
    $report = RegistryDocsCheck::forPackage(dirname(__DIR__))->run();

    expect($report->failures())->toBe([], $report->summary())
        ->and($report->coverage)->toHaveCount(2);   // the number of roots this package declares
});
```

`dirname(__DIR__)` is the package root when the test sits directly in `tests/`; a test in
`tests/Feature/` uses `dirname(__DIR__, 2)`. A package without the directory gets an inactive report,
which passes. The check only reads.

The same check runs from artisan, for a CI step or a host:

```bash
php artisan popcorn:registries:docs            # the root package
php artisan popcorn:registries:docs path/to/pkg --json
```

It exits non-zero when anything is uncovered or has drifted.

## Tests

```bash
composer install
vendor/bin/pest
```

## Deploy step: bake the registries

Production (and any `config:cache`d process) resolves registries from the BAKED index:

```
php artisan popcorn:registries:cache
```

Run it on deploy, after `composer install` — the live-vendor-reconcile runbook already ends with
it. Without the bake, resolution throws `UnbakedRegistryIndex` (loud by design: an unbaked index
answering from memory would be a different registry per process). Local dev auto-bakes via
testbench; this paragraph exists because the step was previously learned from the exception
(architecture-pass A1-C3).

## Licence

MIT
