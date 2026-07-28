<?php

namespace Rushing\Popcorn\Runner\Exceptions;

use RuntimeException;
use Rushing\Popcorn\Contracts\Runner;
use Rushing\Popcorn\Invocables\RunnerInvocable;
use Rushing\Popcorn\Runner\Result;

/**
 * The base of the run-failure hierarchy (popcorn-runner ticket 06). A total {@see Result} is what
 * a {@see Runner} returns; the *throwing* lives one layer up, in
 * {@see Result::throw()} and the {@see RunnerInvocable} adapter, which
 * preserves `ProcessInvocable`'s array-out-or-throw contract.
 *
 * Every subclass carries the full `Result` (`$e->result`) so a catcher still sees telemetry, and
 * `catch (GrantDenied)` reads distinctly from `catch (RunTimedOut)`.
 */
class RunFailed extends RuntimeException
{
    public function __construct(
        public Result $result,
        ?string $message = null,
    ) {
        parent::__construct($message ?? $result->error ?? "popcorn: run failed ({$result->outcome->value}).");
    }
}
