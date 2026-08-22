<?php

namespace Rushing\Popcorn\Laravel;

use Rushing\Popcorn\InvocableRegistry;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\Exceptions\AmbiguousRegistryMatch;
use Rushing\Popcorn\Registries\Exceptions\UnregisteredRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * The Laravel-side root the {@see Facades\Popcorn} facade fronts: the kernel's {@see RegistryIndex}
 * plus the affordances that can only exist on this side of the topology line.
 *
 * ## Why this exists at all
 *
 * Not because "a container-bound manager is best practice" — the estate's own facade census reads the
 * instinct as historically too loose, and `Popcorn` sits in that census's zero-external-consumers
 * bucket. It exists because `rushing/php-popcorn` declares
 * `package-topology.mustNotRequire: ["illuminate/*"]`, which is the mechanically-checked rule that
 * guarantees the kernel's generality (registry-kernel ticket 10 D2). So a Laravel-side affordance —
 * {@see suggest()}, a Gate-backed {@see Authorizer} — cannot hang off the kernel index, and the only
 * remaining alternative was a static method on the facade class that resolves a binding and forwards
 * to it, which is precisely the shape `beam-facade` ticket 25 names as "a facade written badly".
 *
 * ## Closed at birth
 *
 * `splicewire/laravel-beam`'s `BeamManager` is the worked precedent, and the part worth copying is
 * that its surface was closed: no `Macroable`, no runtime extension point, siblings extending by
 * registering into a core-owned registry instead. This is closed the same way, at birth rather than
 * after accretion — which for a REGISTRY kernel is barely a constraint, since registering into
 * something is the whole product.
 *
 * It is bound `scoped()` rather than `singleton()`, also following that precedent: under Octane a
 * singleton's ambient state leaks between requests and queue jobs.
 *
 * ## `pop` / `tryPop` are this side's words only
 *
 * The kernel interface stays `resolve()` / `tryResolve()`. What `pop()` adds is the ROUTING — it takes
 * a bare key and finds the registry that owns it without the caller naming a registry first, which is
 * the facade's entire mechanism rather than a convenience laid over one (ticket 06 D14/D15).
 */
class PopcornManager
{
    public function __construct(
        private RegistryIndex $index,
        private InvocableRegistry $invocables,
    ) {}

    /** The index itself, for describing into and for tooling that walks the whole tree. */
    public function index(): RegistryIndex
    {
        return $this->index;
    }

    /**
     * Resolve a bare key through the registry that owns it.
     *
     * Two failures, deliberately different: {@see UnregisteredRegistry} when no declared root claims
     * the key at all, and the owning registry's own {@see \Rushing\Popcorn\Registries\Exceptions\RegistryMiss}
     * when it claims the key but has nothing there. A typo'd namespace and a typo'd leaf are different
     * operator errors with different fixes (ticket 13 D1).
     *
     * @throws UnregisteredRegistry
     * @throws \Rushing\Popcorn\Registries\Exceptions\RegistryMiss
     */
    public function pop(RegistryKey|string $key): mixed
    {
        return $this->index->ownerOf($key)->resolve($key);
    }

    /**
     * The same routed lookup, `null` on a miss — including "no registry claims this key".
     *
     * **Ambiguity still throws**, mirroring `tryResolve()`: a miss is a question with no answer and the
     * caller opted into handling that; ambiguity is a question with several, and a silent null is how a
     * duplicate registration survives to production.
     *
     * @throws AmbiguousRegistryMatch
     */
    public function tryPop(RegistryKey|string $key): mixed
    {
        $owner = $this->index->routeTo($key);

        return $owner?->tryResolve($key);
    }

    /**
     * The registry described at exactly `$root` — the OWNER's class where one was named, so a caller
     * gets `ParticleResourceRegistry` with its domain sugar rather than the storage it delegates to.
     */
    public function registry(RegistryKey|string $root): ?object
    {
        return $this->index->owner($root);
    }

    /**
     * The registry that OWNS `$key` by longest-prefix routing — the storage a read is handed to, which
     * is a different question from {@see registry()}'s "what is described at this exact root".
     */
    public function routeTo(RegistryKey|string $key): ?Registry
    {
        return $this->index->routeTo($key);
    }

    /**
     * Install the host's authorization policy across every registry in the estate.
     *
     * The APP calls this, not a package. There is exactly one authorizer (ticket 09 D7), so a package
     * that installed one would be deciding policy for a host that never asked — the register-down rule
     * broken in the direction that matters most. Packages ship {@see Authorizer} implementations;
     * `rushing/laravel-popcorn` ships {@see Authorization\GateAuthorizer} and installs none of them.
     */
    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->index->authorizeWith($authorizer);

        return $this;
    }

    /**
     * Keys close to one that missed, for an error message or a validation hint — nearest first,
     * capped at three, ties broken by registration order.
     *
     * Public, resolvable and mockable on purpose (ticket 13 D6): a global helper would be none of the
     * three. It reads the index FILTERED — a suggestion runs with an actor present, and suggesting a
     * key the caller may not see would leak exactly what the authorizer hides.
     *
     * It lives here rather than on the throw path, which is the reversible direction: a miss can grow
     * suggestions later, but a kernel exception that had learned to enumerate could not un-learn it.
     *
     * @return list<string>
     */
    public function suggest(RegistryKey|string $key, int $limit = 3, int $within = 3): array
    {
        $needle = (string) $key;
        $owner = $this->index->routeTo($key);

        $candidates = $owner === null
            ? array_merge(...array_map(
                static fn (Registry $registry): array => $registry->keys(),
                array_values(array_filter(
                    $this->index->matches(Key::root()),
                    // The index is itself an entry of the index (it self-hosts), and its keys are
                    // ROOTS rather than entry keys — including the empty one. Suggesting those in
                    // answer to a missed entry key would be a category error.
                    fn (mixed $entry): bool => $entry instanceof Registry && $entry !== $this->index,
                )),
            ))
            : $owner->keys();

        $scored = [];

        foreach ($candidates as $candidate) {
            $rendered = (string) $candidate;
            $distance = levenshtein($needle, $rendered);

            if ($distance <= $within) {
                $scored[] = ['key' => $rendered, 'distance' => $distance];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);

        return array_slice(array_column($scored, 'key'), 0, $limit);
    }

    /**
     * Dispatch a json-ns handler by URI.
     *
     * Forwarded unchanged to {@see InvocableRegistry} — the accessor moved to this class, the
     * implementation did not. Re-expressing invocable dispatch as a read through the index is
     * registry-kernel ticket 30's cutover, and coupling two unblocked tickets to avoid one forwarding
     * method would have been the worse trade. This is not a deprecation alias: it is the same method on
     * its new host, waiting to be collapsed.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function invoke(string $uri, array $envelope): array
    {
        return $this->invocables->invoke($uri, $envelope);
    }

    public function has(string $uri): bool
    {
        return $this->invocables->has($uri);
    }
}
