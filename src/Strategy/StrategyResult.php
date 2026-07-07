<?php

namespace Rushing\Popcorn\Strategy;

/** What a strategy produced: a value, how confident it is, and which rung found it. */
class StrategyResult
{
    public function __construct(
        public mixed $value,
        public float $confidence,
        public string $strategy,
    ) {}
}
