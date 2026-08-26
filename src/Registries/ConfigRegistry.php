<?php

namespace Rushing\Popcorn\Laravel\Registries;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Filled;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\Nested;
use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Registries\RegistryNode;

/**
 * A registry whose STORAGE is a host config array — the adapter for the estate's objectless registries.
 *
 * ## The population this exists for
 *
 * A handful of the estate's real registries have no class at all: `config('data-schemas.strategies')`
 * takes five cross-vendor registrants and `config('beam.client.sources')` binds one route-manifest
 * source per realm, and both are filled by service providers reading, appending and writing the array
 * back. They are invisible to **every** mechanism this map built — no container-binding scan sees them
 * (registry-kernel ticket 08), and no attribute scan sees them either (ticket 21), because there is
 * nothing to attach an attribute TO. Leaving them `exempt: foreign` would make the destination's "every
 * registry accounted for" a claim the surgeon gate is structurally blind to.
 *
 * So the adapter IS the missing class: a ~5-line concrete subclass carrying its own {@see IsRegistry}
 * and its config path, bound as a singleton **in the provider of whoever owns the config key** — which
 * keeps ticket 08 D7's ownership rule (a registry describes itself; nobody describes on another's
 * behalf) unmodified. The config array stays the storage; the subclass is the declaration.
 *
 * ## Not to be confused with `ConfigRegistrar`
 *
 * {@see \Rushing\Popcorn\Registries\Registrars\ConfigRegistrar} is a **reader** that fills some *other*
 * registry from a config array, once, at boot; what it writes lives in that registry's own storage.
 * `ConfigRegistry` is a **registry whose storage is the config array itself** — there is no copy, and a
 * write goes back to the repository. Deliberately similar names because they share the source; they sit
 * on opposite sides of the seam.
 *
 * That difference is load-bearing rather than academic. `schemastud/laravel-frame` appends its four
 * strategies in `packageBooted()`, after packages that booted earlier have already appended theirs — a
 * snapshot taken at construction would silently miss every late registrant. Reading through to the
 * repository on each read is what makes the adapter honest about a store it does not own.
 *
 * ## Projected onto `BasicRegistry`, rebuilt per read
 *
 * Every read builds a {@see BasicRegistry} from the current config array and asks it the question. That
 * is not a cache miss — it is the point: root stamping, registration order, `OnDuplicate`, ambiguity and
 * the authorization filter are then the kernel's ONE implementation of registry semantics rather than a
 * second one drifting beside it. The arrays are three to seven entries; the projection costs nothing
 * that matters, and it cannot go stale.
 *
 * {@see \Rushing\Popcorn\Registries\RecordsSupersession} is therefore NOT implemented, and the absence
 * is information: a config array holds values, not history. Overwriting a key here leaves no record
 * because there is nowhere in the storage to keep one, and faking uniformity by keeping a shadow ledger
 * beside a store we do not own is the reaching-past-the-boundary ticket 08 D11 refuses.
 * {@see \Rushing\Popcorn\Registries\Forgettable} is absent for the same reason at one remove: `forget()`
 * is expressible as an `unset`, but `forgetBy()` is not — config records no registrant per entry — and a
 * half-honoured interface is worse than an unimplemented one.
 *
 * ## `null` is an unset slot, never an entry
 *
 * `'operator' => env('BEAM_CLIENT_OPERATOR_SOURCE')` is how the estate spells "nobody bound this", and
 * its consumers read it as absence (`is_string($binding) && $binding !== ''`). So a null value is
 * skipped and `has()` answers false — the registry reports what the host means rather than what the
 * array literally contains.
 *
 * @see keyFor() for the one thing a subclass may need to supply.
 *
 * @template TEntry
 *
 * @implements Registry<TEntry>
 */
abstract class ConfigRegistry implements Filled, Gated, Nested, Registry
{
    /** @var list<Registrar> */
    private array $registrars = [];

    private ?Authorizer $authorizer = null;

    public function __construct(protected Repository $config) {}

    /**
     * The dotted config path this registry's entries live at, e.g. `data-schemas.strategies`.
     *
     * Distinct from the registry's `root`, which is the branch of the *keyspace* it owns. The two often
     * read alike and are not the same thing: a host may republish the same registry under a different
     * config path, and a rekey of the root must not move anyone's config file.
     */
    abstract protected function configKey(): string;

    /**
     * The key an entry registers under, given its position in the config array.
     *
     * The default answers for the shape that already carries keys — `['defaults' => …, 'operator' => …]`
     * — by taking the array key as written. A **list**-shaped config has no keys to take, and this
     * throws rather than inventing one: keying by ordinal would make every key change the moment
     * somebody appends, and kebab-casing a class name in the kernel is the guess
     * {@see \Rushing\Popcorn\Registries\Registrars\AttributeRegistrar} and
     * {@see BasicRegistry::for()} both refuse at this exact altitude.
     *
     * A list-shaped subclass overrides this and says explicitly where its key comes from —
     * {@see Key::fromClass()} being the sanctioned derivation when entries are class-strings. Explicit
     * at one site by the owner is the difference between a declaration and a guess.
     */
    protected function keyFor(int|string $index, mixed $entry): RegistryKey|string
    {
        if (is_string($index)) {
            return $index;
        }

        throw new InvalidArgumentException(sprintf(
            '`%s` reads `config(\'%s\')`, which is a LIST — it has no keys to register under, and an '
                .'ordinal would change every key the first time somebody appends. Override `keyFor()` '
                .'to say where the key comes from (Key::fromClass($entry) where entries are '
                .'class-strings).',
            static::class,
            $this->configKey(),
        ));
    }

    /** The declaration this class carries, which is what makes it the registry the array never had. */
    public function declaration(): IsRegistry
    {
        return IsRegistry::of(static::class) ?? throw new InvalidArgumentException(sprintf(
            '`%s` extends ConfigRegistry but carries no #[IsRegistry], so it cannot say what root it '
                .'owns. The declaration is the whole reason the subclass exists.',
            static::class,
        ));
    }

    public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static
    {
        $slot = $this->slotFor(Key::of($key));
        $entries = $this->entries();

        // Preserve the shape found. A list-shaped config is read as a list by every existing consumer
        // (`in_array`, `foreach`, an ordered pipeline), so turning it into a map on the first write
        // would break them all; a map-shaped one keys by slot. An EMPTY array is treated as a map,
        // because a map is the general case and a list is the special one — a list-shaped subclass has
        // already had to override `keyFor()`, so it is the one that knows better.
        if ($entries !== [] && array_is_list($entries)) {
            $existing = $this->indexOfSlot($entries, $slot);

            $existing === null
                ? $entries[] = $entry
                : $entries[$existing] = $entry;
        } else {
            $entries[$slot] = $entry;
        }

        $this->config->set($this->configKey(), $entries);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->store()->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store()->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store()->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->store()->matches($key);
    }

    public function keys(): array
    {
        return $this->store()->keys();
    }

    public function children(RegistryKey|string $key): array
    {
        return $this->store()->children($key);
    }

    public function descendants(RegistryKey|string $key): array
    {
        return $this->store()->descendants($key);
    }

    public function nodeAt(RegistryKey|string $key): RegistryNode
    {
        return $this->store()->nodeAt($key);
    }

    public function unfiltered(): Registry
    {
        $unfiltered = clone $this;
        $unfiltered->authorizer = null;

        return $unfiltered;
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->authorizer = $authorizer;

        return $this;
    }

    /**
     * Attach a registrar and let it fill this registry now.
     *
     * The registrar's writes land in the config repository like any other, so there is no second store
     * to keep in step — `registrars()` records the attachment for the index's "how do I contribute to
     * this?" column, and nothing else.
     */
    public function attach(Registrar $registrar): void
    {
        $this->registrars[] = $registrar;

        $registrar->fill($this);
    }

    /** @return list<Registrar> */
    public function registrars(): array
    {
        return $this->registrars;
    }

    /**
     * The current config array projected onto the kernel's one implementation of the contract.
     *
     * Built per call on purpose — see the class docblock. Registration order is the config array's own
     * order, which is what a `RunAll` pipeline like `data-schemas.strategies` means by it.
     */
    /** @return BasicRegistry<TEntry> */
    protected function store(): BasicRegistry
    {
        $store = new BasicRegistry($this->declaration(), $this->authorizer);

        foreach ($this->entries() as $index => $entry) {
            if ($entry === null) {
                continue;
            }

            $store->register($this->keyFor($index, $entry), $entry, by: "config {$this->configKey()}");
        }

        return $store;
    }

    /** @return array<int|string, mixed> */
    protected function entries(): array
    {
        $entries = $this->config->get($this->configKey(), []);

        return is_array($entries) ? $entries : [];
    }

    /**
     * The config array's own key for a registry key — the relative portion, with the declared root
     * stripped back off.
     *
     * Keys go relative in and absolute out (ticket 20 D2), and a config file spells the relative form:
     * `beam.client.sources` holds `operator`, not `beam.client.sources.operator`. So a caller may pass
     * either and the array sees the same slot.
     *
     * One level only. A config array key containing a dot would nest under Laravel's repository on the
     * way in and read back as an array on the way out — a slot that is not the entry that was written.
     * A host wanting a deeper shape flattens it in its own vocabulary before it gets here, which is the
     * same line {@see \Rushing\Popcorn\Registries\Registrars\ConfigRegistrar} draws.
     */
    protected function slotFor(RegistryKey $key): string
    {
        $root = $this->declaration()->rootKey()->segments();
        $segments = $key->segments();

        if (array_slice($segments, 0, count($root)) === $root) {
            $segments = array_slice($segments, count($root));
        }

        if (count($segments) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '`%s` cannot store `%s`: a config array key is one level, and `%s` is %d. Flatten it in '
                    .'your own vocabulary before registering.',
                static::class,
                (string) $key,
                (string) $key,
                count($segments),
            ));
        }

        return $segments[0];
    }

    /**
     * Where in a list-shaped config the entry currently keyed `$slot` sits, or null if none does.
     *
     * Asked through {@see keyFor()} rather than by comparing entries, so a subclass that derives its key
     * from the entry gets replace-in-place — and the ordered pipeline keeps its position — instead of a
     * duplicate appended at the end.
     *
     * Takes a LIST, not an array: the one caller reaches this only inside `array_is_list($entries)`,
     * so the index it hands back is an int by construction. Declared `array<int|string, mixed>` it was
     * a `?int` return that could hand back a string — found by the level-8 run, 2026-08-26.
     *
     * @param  list<mixed>  $entries
     */
    private function indexOfSlot(array $entries, string $slot): ?int
    {
        foreach ($entries as $index => $entry) {
            if ($entry !== null && (string) Key::of($this->keyFor($index, $entry)) === $slot) {
                return $index;
            }
        }

        return null;
    }
}
