<?php

namespace Rushing\Popcorn\Invocables;

use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;
use Rushing\Popcorn\Contracts\Runner;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\Result;

/**
 * The adapter that carries a {@see Runner} into the array-in/array-out {@see Invocable} world
 * (popcorn-runner ticket 01) — the payoff of the whole seam. Because it *is* an Invocable, a
 * sandboxed run is `CachedInvocable`-wrappable and a legal `StrategyLadder` rung with **no** kernel
 * change; the registry overrides it by name like any other capability.
 *
 * `binding()` is {@see Binding::Local} — locality and mechanism are different axes (popcorn ADR-0001):
 * a sandboxed run is still *local* (it does not cross to a remote service); "sandboxed" is a Grant
 * profile, not a Binding. This is also why a satellite-local transform stays free under the metering
 * doctrine.
 *
 * The {@see Grant} is the *effective* one, resolved host-side and injected when the host builds this
 * invocable during hydration (per-invocation resolution ⇒ rebuild the invocable). `invoke()` is
 * literally `run(…)->throw()->output()`, preserving `ProcessInvocable`'s array-out-or-throw contract;
 * Runner-aware callers (Conduit, meter, audit) read the total {@see Result} via {@see run()} instead.
 */
class RunnerInvocable implements Invocable
{
    public function __construct(
        private string $name,
        private Runner $runner,
        private Manifest $manifest,
        private Grant $grant,
        private ?string $description = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description ?? $this->manifest->description;
    }

    public function binding(): Binding
    {
        return Binding::Local;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function invoke(array $input): array
    {
        return $this->run($input)->throw()->output();
    }

    /**
     * The Runner-aware read path: the total {@see Result} (never throws for a run outcome) — for the
     * Conduit overlay, the meter, and the audit log that want telemetry and the {@see Outcome}.
     *
     * @param  array<string, mixed>  $input
     */
    public function run(array $input): Result
    {
        return $this->runner->run($this->manifest, $this->grant, $input);
    }

    public function manifest(): Manifest
    {
        return $this->manifest;
    }

    public function grant(): Grant
    {
        return $this->grant;
    }
}
