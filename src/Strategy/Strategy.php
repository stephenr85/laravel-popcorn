<?php

namespace Rushing\Popcorn\Strategy;

/**
 * One rung of a {@see StrategyLadder}: it either produces a result it can stand
 * behind, or abstains (returns null) so the ladder demotes to the next rung. The
 * self-validate-and-demote discipline — a strong rung that can't be sure steps
 * aside rather than guessing.
 */
interface Strategy
{
    public function name(): string;

    /**
     * @param  array<string, mixed>  $input
     */
    public function attempt(array $input): ?StrategyResult;
}
