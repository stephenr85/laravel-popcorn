<?php

namespace Rushing\Popcorn\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Rushing\Popcorn\Laravel\PopcornManager;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Registries\Rootable;

/**
 * `'realm' => ['required', new ExistsInRegistry('beam.realm')]` — the submitted value must be a live,
 * VISIBLE key at or under that prefix.
 *
 * The estate's first package-shipped validation rule (registry-kernel ticket 13 D0): there was no
 * `Rules/` directory and no `implements ValidationRule` anywhere across the three vendors, so this sets
 * the house pattern. `splicewire/laravel-beam-accounts`' `*ValidationRules` concerns return rule ARRAYS
 * and are a different thing.
 *
 * A prefix still names the scope; globs are not accepted. `relative: true` declares suffix-only
 * wire values, and `where` can narrow visible canonical keys to a domain subset. `values()` exposes
 * exactly that live vocabulary for schemas and route documentation, under the current authorizer.
 * Recompute it for each publication context; do not cache one actor's vocabulary for another.
 *
 * ## It asks the registry the question the resolver will ask
 *
 * Scope is {@see Key::isUnder()} and existence is {@see Registry::has()} — the same segment-wise code
 * the read path uses, never a `str_starts_with()` beside it. That is the load-bearing constraint from
 * 13 §4: a second code path is how you get a form that accepts a value the resolver then rejects. So
 * `beam.realms` does not validate against `beam.realm`, for the same reason it does not resolve there.
 *
 * ## Two checks, two messages, and the order is not the call site's to get wrong
 *
 * Legality first — {@see Key::tryParse()}, touching no registry — short-circuiting with a *declaration*
 * message. Only then the lookup, whose failure is a *miss* message carrying suggestions. Splitting
 * these into two rules would put the ordering in the caller's hands, where it can be got wrong once per
 * form (13 D8).
 *
 * ## Always filtered
 *
 * The rule never calls {@see Registry::unfiltered()} (13 D3). A hidden entry fails validation with the
 * message an absent one gets, byte-identical, because
 * {@see \Rushing\Popcorn\Registries\Exceptions\MissReason::Filtered} already renders as `Absent` — an
 * error message that distinguished them would be the existence oracle the whole authorization seam
 * exists to close. The suggester is likewise filtered; leaking through "did you mean" would look like a
 * nicety.
 *
 * ## Three developer errors, thrown rather than failed
 *
 * A validation failure blames the person filling in the form. These are not their fault, so they throw:
 *
 * - **A prefix or registry whose key type the kernel cannot address** (13 D4, narrowed by ticket 64).
 *   `(string) $key` does not round-trip for a consumer's own {@see RegistryKey}, so string comparison
 *   there compares renderings — and such a key is never root-stamped, which puts the registry's entries
 *   outside the global keyspace entirely. The rule refusing them agrees with the kernel rather than
 *   adding a restriction.
 *
 *   ⚠️ **This is narrower than it was, and the narrowing is behavioural.** It used to read *foreign-keyed*
 *   and be implemented as `instanceof Key`, which is the same conflation ticket 58 D3 found in
 *   `BasicRegistry::door()`: **canonical** standing in for **addressable**. A
 *   {@see \Rushing\Popcorn\Registries\RelativeUriKey} is neither foreign nor canonical — it is the
 *   kernel's own parser for slash-spelled references, and it hands the door a `Key`. Such a registry is
 *   now accepted, because after its door there is nothing non-dotted left to compare. What is still
 *   refused is what genuinely has no address: a consumer's own key type, and
 *   {@see \Rushing\Popcorn\Registries\AbsoluteUriKey}, which is kernel-owned and declines stamping
 *   openly.
 * - **Nothing claims the prefix at all.** {@see \Rushing\Popcorn\Registries\Exceptions\UnregisteredRegistry}
 *   is a SIBLING of `RegistryMiss`, not a subclass (20's input to this ticket), so the two paths are
 *   genuinely separate: a key naming no registry means the rule points at a registry that never
 *   described itself — usually a provider that did not boot — and answering "invalid" would blame the
 *   user for a boot ordering bug.
 */
class ExistsInRegistry implements ValidationRule
{
    private Key $prefix;

    /** @param (Closure(Key): bool)|null $where */
    public function __construct(
        RegistryKey|string $prefix,
        private bool $relative = false,
        private ?Closure $where = null,
    ) {
        $coerced = Key::of($prefix);
        $original = $coerced;

        // Asking the key to root itself at the root of the WHOLE tree is how "can the kernel address
        // this?" gets asked without a class list: a Rootable that is really a parser over the dotted
        // keyspace answers with the Key it parses to, and one that declines answers with itself. A
        // zero-segment root makes the call a no-op for anything already addressable, so this coerces
        // without ever relocating a key (ticket 64).
        if ($coerced instanceof Rootable) {
            $coerced = $coerced->underRoot(Key::root());
        }

        if (! $coerced instanceof Key) {
            throw new InvalidArgumentException(sprintf(
                'ExistsInRegistry was pointed at `%s`, a %s, whose keys the kernel cannot address. Such '
                    .'a key is never stamped with a root, so its registry has no address in the global '
                    .'keyspace for a prefix to name. Reach that registry through the index instead.',
                $original,
                $original::class,
            ));
        }

        $this->prefix = $coerced;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $key = $this->key($value);

        // Legality first, and it touches no registry: an illegal key could not be registered, so
        // asking a registry about it would only turn a spelling error into a lookup miss.
        if ($key === null) {
            $fail('The :attribute is not a legal registry key: expected dot-separated lowercase '
                .'segments, e.g. `beam.realm.overlays`. Segments may join groups with `-`, `_` or `:`, '
                .'but never lead, trail or double one.');

            return;
        }

        $this->assertUsable();

        if (! $this->accepts($key)) {
            $this->miss($fail, $key);
        }
    }

    /** Parse the declared wire spelling without consulting a registry. */
    public function key(mixed $value): ?Key
    {
        $key = is_string($value) ? Key::tryParse($value) : null;

        if ($key === null || ! $this->relative) {
            return $key;
        }

        // Relative means a suffix, never the kernel door's absolute-or-relative heuristic. This
        // ensures the accepted spellings and the emitted enum have exactly the same membership.
        return Key::fromSegments([...$this->prefix->segments(), ...$key->segments()]);
    }

    public function contains(mixed $value): bool
    {
        $key = $this->key($value);

        if ($key === null) {
            return false;
        }

        $this->assertUsable();

        return $this->accepts($key);
    }

    /**
     * Visible accepted wire values, sorted for reproducible schema output. An empty array means
     * nothing is accepted; schema consumers must preserve that, not omit the constraint.
     *
     * @return list<string>
     */
    public function values(): array
    {
        $this->assertUsable();
        $index = $this->popcorn()->index();
        $values = [];

        // Include the owning ancestor and nested roots: longest-prefix routing may hand a value
        // under this prefix to a deeper registry. Exclude the self-hosted index's registry names.
        $registries = [$this->popcorn()->routeTo($this->prefix), ...$index->matches($this->prefix)];

        foreach ($registries as $registry) {
            if ($registry === null || $registry === $index) {
                continue;
            }

            foreach ($registry->keys() as $key) {
                if ($key instanceof Key && $this->accepts($key)) {
                    $values[] = $this->wireValue($key);
                }
            }
        }

        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    private function accepts(Key $key): bool
    {
        if (! $key->equals($this->prefix) && ! $key->isUnder($this->prefix)) {
            return false;
        }

        if ($this->relative && $key->equals($this->prefix)) {
            return false;
        }

        // Route on the value: nested registry roots are legal. Check visibility before invoking a
        // domain predicate, so even the predicate cannot observe an entry hidden from this actor.
        return ($this->popcorn()->routeTo($key)?->has($key) ?? false)
            && ($this->where === null || ($this->where)($key));
    }

    private function wireValue(Key $key): string
    {
        return $this->relative
            ? implode('.', array_slice($key->segments(), count($this->prefix->segments())))
            : (string) $key;
    }

    /**
     * The two developer errors, checked once per validated value and before any user-facing failure.
     *
     * Deliberately after the legality short-circuit rather than in the constructor: the index is filled
     * by owners describing into it at THEIR `boot()`, and a rule instantiated in a form request built
     * during that boot would refuse a registry that is about to arrive.
     */
    private function assertUsable(): void
    {
        $registry = $this->popcorn()->routeTo($this->prefix);

        if ($registry === null) {
            throw new InvalidArgumentException(sprintf(
                'No registry claims `%s`, so ExistsInRegistry has nothing to validate against. This is '
                    .'usually a service provider that did not describe its registry into the index, not '
                    .'a bad prefix — run `php artisan popcorn:registries` to see what did.',
                $this->prefix,
            ));
        }

        foreach ($registry->keys() as $key) {
            if ($key instanceof Key) {
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'The registry claiming `%s` is keyed by %s, which the kernel cannot address. Its entries '
                    .'are outside the global keyspace — never stamped with a root, and `(string) $key` '
                    .'does not round-trip — so a prefix cannot name them and comparing renderings would '
                    .'silently compare the wrong thing. Validate it through its own owner instead.',
                $this->prefix,
                $key::class,
            ));
        }
    }

    /**
     * The one user-facing failure: out of scope and absent render identically, and so does hidden.
     *
     * Suggestions come from {@see PopcornManager::suggest()} — the shared helper, filtered, reached
     * through the Laravel-side root (13 D6, settled onto `suggest()` by ticket 20). They are then cut
     * back to this rule's own prefix, because a "did you mean" naming a key in a registry the field does
     * not accept is noise at best and tells the caller about a neighbouring keyspace at worst.
     */
    private function miss(Closure $fail, Key $key): void
    {
        $suggestions = array_values(array_filter(
            $this->popcorn()->suggest($key),
            fn (string $candidate): bool => ($parsed = Key::tryParse($candidate)) !== null
                && $this->accepts($parsed),
        ));

        $message = "The :attribute is not a registered key under `{$this->prefix}`.";

        if ($suggestions !== []) {
            $message .= ' Did you mean '.implode(', ', array_map(
                fn (string $candidate): string => "`{$this->wireValue(Key::parse($candidate))}`",
                $suggestions,
            )).'?';
        }

        $fail($message);
    }

    /**
     * Resolved per call rather than injected, so the rule stays `new`-able at a call site — a validation
     * rule that had to be built by the container would not be usable in the array form every consumer
     * writes.
     */
    private function popcorn(): PopcornManager
    {
        return app(PopcornManager::class);
    }
}
