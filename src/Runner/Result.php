<?php

namespace Rushing\Popcorn\Runner;

use JsonException;
use Rushing\Popcorn\Contracts\Runner;
use Rushing\Popcorn\Invocables\RunnerInvocable;
use Rushing\Popcorn\Runner\Exceptions\BuildFailed;
use Rushing\Popcorn\Runner\Exceptions\GrantDenied;
use Rushing\Popcorn\Runner\Exceptions\MalformedOutput;
use Rushing\Popcorn\Runner\Exceptions\NonZeroExit;
use Rushing\Popcorn\Runner\Exceptions\ResourceLimitExceeded;
use Rushing\Popcorn\Runner\Exceptions\RunFailed;
use Rushing\Popcorn\Runner\Exceptions\RunTimedOut;
use Rushing\Popcorn\Runner\Exceptions\SubstrateUnavailable;
use Throwable;

/**
 * A **total** value object at the {@see Runner} seam (popcorn-runner
 * ticket 06): every run, success or failure, returns one. The `match`-able {@see Outcome} is the
 * spine every consumer discriminates on; the *throwing* lives one layer up ({@see Result::throw()}).
 *
 * Channel split: `rawOutput` is the always-populated value artifact (`output()` decodes it);
 * `stderr` is the bounded diagnostic side-channel captured on *every* outcome (the authoring debug
 * loop). Compute telemetry (`wallMs`/`cpuMs`/`memPeakBytes`/…) is for observability + limit
 * enforcement, **not** presumed to be the billed quantity — the meter reads its quantity off the
 * output via `MeteredInvocable::meterQuantity(Result)`, and a `Binding::Local` run stays free.
 */
class Result
{
    /**
     * @param  ?GrantAxis  $deniedAxis  set only on {@see Outcome::GrantDenied} — the axis of the security event
     * @param  ?string  $deniedTarget  set only on {@see Outcome::GrantDenied} — what was touched ("/etc", "host:443")
     */
    public function __construct(
        public Outcome $outcome,
        public string $rawOutput = '',
        public string $stderr = '',
        public bool $stderrTruncated = false,
        public ?string $error = null,
        public ?int $wallMs = null,
        public ?int $cpuMs = null,
        public ?int $memPeakBytes = null,
        public ?int $exitCode = null,
        public ?int $signal = null,
        public bool $limitHit = false,
        public bool $sandboxed = true,
        public ?GrantAxis $deniedAxis = null,
        public ?string $deniedTarget = null,
    ) {}

    /**
     * The decoded value — array-out, so a {@see RunnerInvocable} reads
     * identically to a local invocable. Empty on any non-`Success` outcome (a failed run has no value).
     *
     * @return array<string, mixed>
     */
    public function output(): array
    {
        if (! $this->successful()) {
            return [];
        }

        try {
            $decoded = json_decode(trim($this->rawOutput), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    public function successful(): bool
    {
        return $this->outcome === Outcome::Success;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * Laravel-`ProcessResult`-style: a no-op on success, throws on failure. The default maps the
     * {@see Outcome} to a typed {@see RunFailed} subclass carrying this Result; pass `$via` to
     * override at the call site (it receives the Result, returns a throwable, or null to suppress).
     */
    public function throw(?callable $via = null): static
    {
        if ($this->successful()) {
            return $this;
        }

        if ($via !== null) {
            $throwable = $via($this);

            if ($throwable instanceof Throwable) {
                throw $throwable;
            }

            return $this;
        }

        throw $this->toException();
    }

    public function throwIf(bool|callable $condition, ?callable $via = null): static
    {
        $shouldThrow = is_callable($condition) ? (bool) $condition($this) : $condition;

        return $shouldThrow ? $this->throw($via) : $this;
    }

    public function throwUnless(bool|callable $condition, ?callable $via = null): static
    {
        $condition = is_callable($condition) ? (bool) $condition($this) : $condition;

        return $this->throwIf(! $condition, $via);
    }

    /** The typed exception the default `throw()` would raise (null on success). */
    public function toException(): ?RunFailed
    {
        return match ($this->outcome) {
            Outcome::Success => null,
            Outcome::NonZeroExit => new NonZeroExit($this),
            Outcome::Timeout => new RunTimedOut($this),
            Outcome::ResourceLimitKill => new ResourceLimitExceeded($this),
            Outcome::GrantDenied => new GrantDenied($this),
            Outcome::SubstrateUnavailable => new SubstrateUnavailable($this),
            Outcome::MalformedOutput => new MalformedOutput($this),
            Outcome::BuildFailed => new BuildFailed($this),
        };
    }

    // --- Convenience constructors the substrates use to keep call-sites legible -----------------

    public static function success(string $rawOutput, string $stderr = '', bool $stderrTruncated = false): self
    {
        return new self(Outcome::Success, rawOutput: $rawOutput, stderr: $stderr, stderrTruncated: $stderrTruncated);
    }

    public static function grantDenied(GrantAxis $axis, string $target, ?string $error = null): self
    {
        return new self(
            Outcome::GrantDenied,
            error: $error ?? "popcorn: grant denied on axis `{$axis->value}` for `{$target}`.",
            deniedAxis: $axis,
            deniedTarget: $target,
        );
    }

    public static function substrateUnavailable(string $error): self
    {
        return new self(Outcome::SubstrateUnavailable, error: $error);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'error' => $this->error,
            'output' => $this->output(),
            'stderr' => $this->stderr,
            'stderrTruncated' => $this->stderrTruncated,
            'telemetry' => [
                'wallMs' => $this->wallMs,
                'cpuMs' => $this->cpuMs,
                'memPeakBytes' => $this->memPeakBytes,
                'exitCode' => $this->exitCode,
                'signal' => $this->signal,
                'limitHit' => $this->limitHit,
                'sandboxed' => $this->sandboxed,
            ],
            'deniedAxis' => $this->deniedAxis?->value,
            'deniedTarget' => $this->deniedTarget,
        ];
    }
}
