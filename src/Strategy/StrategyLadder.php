<?php

namespace Rushing\Popcorn\Strategy;

/**
 * Runs strategies strongest-first, taking the first result that clears the
 * acceptance threshold; weaker rungs are tried only when stronger ones abstain
 * or fall short. Returns null when every rung declines — the caller's reviewer
 * floor. Per-region demotion (a clean front resolved by the strong rung, an
 * ambiguous tail by a weaker one) is just calling resolve per region.
 */
final class StrategyLadder
{
    /** @var Strategy[] */
    private readonly array $rungs;

    public function __construct(
        Strategy ...$rungs,
    ) {
        $this->rungs = $rungs;
    }

    public function resolve(array $input, float $acceptAbove = 0.0): ?StrategyResult
    {
        foreach ($this->rungs as $rung) {
            $result = $rung->attempt($input);

            if ($result !== null && $result->confidence >= $acceptAbove) {
                return $result;
            }
        }

        return null;
    }

    /** @return string[] rung names, strongest-first */
    public function rungs(): array
    {
        return array_map(fn (Strategy $s) => $s->name(), $this->rungs);
    }
}
