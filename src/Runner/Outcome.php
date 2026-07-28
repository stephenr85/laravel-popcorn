<?php

namespace Rushing\Popcorn\Runner;

use Rushing\Popcorn\Strategy\StrategyLadder;

/**
 * The closed spine of a run's verdict (popcorn-runner ticket 06) — the `match`-able case
 * every consumer (meter, audit log, UI badge, strategy ladder) discriminates on, and that
 * serializes to one stable string. A {@see Result} is *total*: every run, success or failure,
 * carries exactly one of these — exceptions are reserved for kernel/programmer errors
 * (an unparseable {@see Manifest}), never run outcomes.
 */
enum Outcome: string
{
    /** The transform ran and returned a well-formed value. */
    case Success = 'success';

    /** The process exited non-zero (the transform's own failure). */
    case NonZeroExit = 'non_zero_exit';

    /** Wall-time cap exceeded; the run was killed. */
    case Timeout = 'timeout';

    /** cpu/mem cap exceeded; the run was killed by the substrate. */
    case ResourceLimitKill = 'resource_limit_kill';

    /** The run touched (or was denied) a capability outside its effective {@see Grant} — a security event. */
    case GrantDenied = 'grant_denied';

    /** The resolved substrate was missing/unrunnable at run time (e.g. bwrap absent) — kept legible, not thrown. */
    case SubstrateUnavailable = 'substrate_unavailable';

    /** stdout/output file was non-JSON or breached the hard cap — a truncated value is a lie, so it is refused. */
    case MalformedOutput = 'malformed_output';

    /** `build: source` compilation rejected the author's source *before* execution (ticket 08 delta). */
    case BuildFailed = 'build_failed';

    public function isSuccess(): bool
    {
        return $this === self::Success;
    }

    /**
     * A denied grant is a policy verdict, not "this strategy didn't work out": it must
     * propagate past a {@see StrategyLadder} (fail-loud), while
     * every capability failure demotes to the next rung (ticket 06).
     */
    public function demotesStrategyLadder(): bool
    {
        return match ($this) {
            self::Success, self::GrantDenied => false,
            default => true,
        };
    }
}
