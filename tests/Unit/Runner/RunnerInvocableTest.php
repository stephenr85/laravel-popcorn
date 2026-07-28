<?php

use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Runner;
use Rushing\Popcorn\Invocables\CachedInvocable;
use Rushing\Popcorn\Invocables\RunnerInvocable;
use Rushing\Popcorn\Runner\Exceptions\NonZeroExit;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\Result;

function fixedRunner(Result $result): Runner
{
    return new class($result) implements Runner
    {
        public function __construct(private Result $result) {}

        public function run(Manifest $m, Grant $g, array $input): Result
        {
            return $this->result;
        }
    };
}

function runnerManifest(): Manifest
{
    return Manifest::fromArray(['name' => 'x', 'runtime' => 'echo', 'entrypoint' => 'e']);
}

it('reports a Local binding — a sandboxed run is still local', function () {
    $inv = new RunnerInvocable('t', fixedRunner(Result::success('{}')), runnerManifest(), Grant::none());

    expect($inv->binding())->toBe(Binding::Local)->and($inv->name())->toBe('t');
});

it('invoke() is run()->throw()->output(): array-out on success', function () {
    $inv = new RunnerInvocable('t', fixedRunner(Result::success(json_encode(['v' => 9]))), runnerManifest(), Grant::none());

    expect($inv->invoke(['in' => 1]))->toBe(['v' => 9]);
});

it('invoke() throws the typed exception on a failed run', function () {
    $inv = new RunnerInvocable('t', fixedRunner(new Result(Outcome::NonZeroExit, error: 'boom')), runnerManifest(), Grant::none());

    expect(fn () => $inv->invoke([]))->toThrow(NonZeroExit::class, 'boom');
});

it('run() exposes the total Result for the meter/audit read path', function () {
    $result = new Result(Outcome::Success, rawOutput: '{}', wallMs: 42);
    $inv = new RunnerInvocable('t', fixedRunner($result), runnerManifest(), Grant::none());

    expect($inv->run([]))->toBe($result)->and($inv->run([])->wallMs)->toBe(42);
});

it('composes under CachedInvocable unchanged', function () {
    $inv = new RunnerInvocable('t', fixedRunner(Result::success(json_encode(['cached' => true]))), runnerManifest(), Grant::none());

    $cached = new CachedInvocable($inv, cache()->store('array'), fn ($in) => 'k', ttl: 60);

    expect($cached->binding())->toBe(Binding::Local)
        ->and($cached->invoke([]))->toBe(['cached' => true]);
});
