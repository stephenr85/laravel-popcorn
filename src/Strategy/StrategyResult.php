<?php

namespace Rushing\Popcorn\Strategy;

/** What a strategy produced: a value, how confident it is, and which rung found it. */
final class StrategyResult
{
    public function __construct(
        public readonly mixed $value,
        public readonly float $confidence,
        public readonly string $strategy,
    ) {}
}
