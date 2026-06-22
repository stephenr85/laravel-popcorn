<?php

use Rushing\Popcorn\Strategy\Strategy;
use Rushing\Popcorn\Strategy\StrategyLadder;
use Rushing\Popcorn\Strategy\StrategyResult;

/** A test rung: yields a fixed result, or abstains when $abstain. */
function rung(string $name, ?float $confidence): Strategy
{
    return new class($name, $confidence) implements Strategy
    {
        public function __construct(private string $n, private ?float $c) {}

        public function name(): string
        {
            return $this->n;
        }

        public function attempt(array $input): ?StrategyResult
        {
            return $this->c === null ? null : new StrategyResult($this->n.':'.($input['x'] ?? ''), $this->c, $this->n);
        }
    };
}

it('takes the first rung that produces a result', function () {
    $ladder = new StrategyLadder(rung('exact', 0.9), rung('fuzzy', 0.6));

    $result = $ladder->resolve(['x' => 'foo']);

    expect($result->strategy)->toBe('exact')
        ->and($result->value)->toBe('exact:foo');
});

it('demotes past an abstaining rung to the next', function () {
    $ladder = new StrategyLadder(rung('exact', null), rung('fuzzy', 0.6));

    expect($ladder->resolve([])->strategy)->toBe('fuzzy');
});

it('demotes past a rung that falls short of the acceptance threshold', function () {
    $ladder = new StrategyLadder(rung('exact', 0.4), rung('fuzzy', 0.8));

    expect($ladder->resolve([], acceptAbove: 0.5)->strategy)->toBe('fuzzy');
});

it('returns null when every rung declines — the reviewer floor', function () {
    $ladder = new StrategyLadder(rung('exact', null), rung('fuzzy', 0.3));

    expect($ladder->resolve([], acceptAbove: 0.5))->toBeNull()
        ->and($ladder->rungs())->toBe(['exact', 'fuzzy']);
});
