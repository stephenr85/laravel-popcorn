<?php

namespace Rushing\Popcorn\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Rushing\Popcorn\Laravel\PopcornManager;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * `'realm' => ['required', new ExistsInRegistry('beam.realm')]` — the submitted value must be a live,
 * VISIBLE key at or under that prefix.
 *
 * The estate's first package-shipped validation rule (registry-kernel ticket 13 D0): there was no
 * `Rules/` directory and no `implements ValidationRule` anywhere across the three vendors, so this sets
 * the house pattern. `splicewire/laravel-beam-accounts`' `*ValidationRules` concerns return rule ARRAYS
 * and are a different thing.
 *
 * ## One argument, a prefix, and no glob
 *
 * There is no second form and no `beam.realm.*` (13 D2). A root IS a prefix, so pointing the rule at a
 * whole registry is the same call; a pattern language would be the mini-language ticket 05 deleted,
 * re-entering through the validator.
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
 * - **A foreign-keyed prefix or registry** (13 D4). `(string) $key` does not round-trip for a consumer's
 *   own {@see RegistryKey}, so string comparison there compares renderings — and per ticket 20 D3 a
 *   foreign key is never stamped, which puts such a registry's entries outside the global keyspace
 *   entirely. The rule refusing them agrees with the kernel rather than adding a restriction.
 * - **Nothing claims the prefix at all.** {@see \Rushing\Popcorn\Registries\Exceptions\UnregisteredRegistry}
 *   is a SIBLING of `RegistryMiss`, not a subclass (20's input to this ticket), so the two paths are
 *   genuinely separate: a key naming no registry means the rule points at a registry that never
 *   described itself — usually a provider that did not boot — and answering "invalid" would blame the
 *   user for a boot ordering bug.
 */
class ExistsInRegistry implements ValidationRule
{
    private Key $prefix;

    public function __construct(RegistryKey|string $prefix)
    {
        $coerced = Key::of($prefix);

        if (! $coerced instanceof Key) {
            throw new InvalidArgumentException(sprintf(
                'ExistsInRegistry was pointed at a foreign registry key (`%s`, a %s). A foreign key is '
                    .'never stamped with a root, so its registry has no address in the global keyspace '
                    .'for a prefix to name. Reach that registry through the index instead.',
                $coerced,
                $coerced::class,
            ));
        }

        $this->prefix = $coerced;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $key = is_string($value) ? Key::tryParse($value) : null;

        // Legality first, and it touches no registry: an illegal key could not be registered, so
        // asking a registry about it would only turn a spelling error into a lookup miss.
        if ($key === null) {
            $fail('The :attribute is not a legal registry key: expected dot-separated lowercase '
                .'segments, e.g. `beam.realm.overlays`. Segments may join groups with `-`, `_` or `:`, '
                .'but never lead, trail or double one.');

            return;
        }

        $this->assertUsable();

        if (! $key->equals($this->prefix) && ! $key->isUnder($this->prefix)) {
            $this->miss($fail, $key);

            return;
        }

        // Routed on the VALUE rather than on the prefix, deliberately: interleaved roots are legal, so
        // a nested registry may own a key that is genuinely under the prefix (ticket 26 D0). Longest
        // prefix picks the registry that owns the key; the scope check above already fixed the branch.
        if (! $this->popcorn()->routeTo($key)?->has($key)) {
            $this->miss($fail, $key);
        }
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
                'The registry claiming `%s` is keyed by %s, a foreign RegistryKey. Its entries are '
                    .'outside the global keyspace — never stamped with a root, and `(string) $key` does '
                    .'not round-trip — so a prefix cannot name them and comparing renderings would '
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
                && ($parsed->equals($this->prefix) || $parsed->isUnder($this->prefix)),
        ));

        $message = "The :attribute is not a registered key under `{$this->prefix}`.";

        if ($suggestions !== []) {
            $message .= ' Did you mean '.implode(', ', array_map(
                static fn (string $candidate): string => "`{$candidate}`",
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
