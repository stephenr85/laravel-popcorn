<?php

namespace Rushing\Popcorn\Laravel\Authorization;

use Illuminate\Contracts\Auth\Access\Gate;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * An {@see Authorizer} that answers through Laravel's `Gate` — the convergence point between the
 * kernel's transport-neutral seam and the framework's ability vocabulary.
 *
 * The two line up almost exactly: `allows(string $ability, RegistryKey $key)` here,
 * `Gate::allows($ability, $arguments)` there, and `splicewire/laravel-beam`'s
 * `AbilityResolver::allows($actor, $ability, $subject = null)` is the same shape a third time. The key
 * is passed through as the gate's argument, so a policy that cares which entry is being asked about can
 * see it, and one that does not simply ignores it.
 *
 * This lives in `rushing/laravel-popcorn` and not in the kernel because the kernel declares
 * `package-topology.mustNotRequire: ["illuminate/*"]`. That is also why the kernel's seam takes no
 * actor: `Gate::allows()` reads the ambient authenticated user, which is a perfectly good answer HERE
 * and would be a wrong one in a kernel that must also serve transports having no such thing.
 *
 * ## It is shipped, not installed
 *
 * `PopcornServiceProvider` does not install this, and no package should install any authorizer. There
 * is exactly one authorizer for the whole estate (registry-kernel ticket 09 D7), so a package that
 * installed one would be choosing policy for a host that never asked — the register-down rule broken in
 * the direction that matters most. The host app opts in:
 *
 * ```php
 * Popcorn::authorizeWith(new GateAuthorizer($this->app->make(Gate::class)));
 * ```
 *
 * ## Why the default is NOT to install it
 *
 * `Gate::allows()` returns **false for an undefined ability**. So installing this by default would turn
 * "this entry declared `manage-invoices` and nobody has written the policy yet" into silent, fleet-wide
 * invisibility — which is the exact class of failure `LensRegistry`'s docblock is written against:
 * *"a silent last-write-wins is how a registry of three lenses reports two."* An entry that declared an
 * ability nothing gates should be a finding, not a disappearance.
 *
 * Note the asymmetry that makes opting IN safe: an entry declaring no ability short-circuits inside the
 * registry and never reaches an authorizer at all, so installing one cannot narrow an already-open
 * surface — it can only enforce gating that entries asked for themselves.
 */
class GateAuthorizer implements Authorizer
{
    public function __construct(private Gate $gate) {}

    public function allows(string $ability, RegistryKey $key): bool
    {
        return $this->gate->allows($ability, $key);
    }
}
