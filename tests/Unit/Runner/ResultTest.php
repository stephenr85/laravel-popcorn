<?php

use Rushing\Popcorn\Runner\Exceptions\GrantDenied;
use Rushing\Popcorn\Runner\Exceptions\MalformedOutput;
use Rushing\Popcorn\Runner\Exceptions\NonZeroExit;
use Rushing\Popcorn\Runner\Exceptions\RunFailed;
use Rushing\Popcorn\Runner\Exceptions\RunTimedOut;
use Rushing\Popcorn\Runner\GrantAxis;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\Result;

it('decodes array-out on success and is empty on failure', function () {
    $ok = Result::success(rawOutput: json_encode(['concept' => 'x']));
    expect($ok->successful())->toBeTrue()->and($ok->output())->toBe(['concept' => 'x']);

    $bad = new Result(Outcome::NonZeroExit, error: 'boom');
    expect($bad->failed())->toBeTrue()->and($bad->output())->toBe([]);
});

it('throw() is a no-op on success and returns itself', function () {
    $ok = Result::success(rawOutput: '{}');
    expect($ok->throw())->toBe($ok);
});

it('throw() maps each outcome to its typed exception carrying the Result', function () {
    expect(fn () => (new Result(Outcome::NonZeroExit))->throw())->toThrow(NonZeroExit::class);
    expect(fn () => (new Result(Outcome::Timeout))->throw())->toThrow(RunTimedOut::class);
    expect(fn () => (new Result(Outcome::MalformedOutput))->throw())->toThrow(MalformedOutput::class);

    try {
        (new Result(Outcome::NonZeroExit, error: 'x', exitCode: 3))->throw();
    } catch (RunFailed $e) {
        expect($e->result->exitCode)->toBe(3);
    }
});

it('carries the denied axis + target on a GrantDenied and it reaches the exception', function () {
    $r = Result::grantDenied(GrantAxis::Paths, '/etc');

    expect($r->outcome)->toBe(Outcome::GrantDenied)
        ->and($r->deniedAxis)->toBe(GrantAxis::Paths)
        ->and($r->deniedTarget)->toBe('/etc');

    try {
        $r->throw();
    } catch (GrantDenied $e) {
        expect($e->deniedAxis)->toBe(GrantAxis::Paths)->and($e->deniedTarget)->toBe('/etc');
    }
});

it('lets the call site override the throwable via a mapper', function () {
    $r = new Result(Outcome::NonZeroExit);

    expect(fn () => $r->throw(fn ($res) => new RuntimeException('mapped: '.$res->outcome->value)))
        ->toThrow(RuntimeException::class, 'mapped: non_zero_exit');
});

it('suppresses the throw when the mapper returns null', function () {
    $r = new Result(Outcome::NonZeroExit);
    expect($r->throw(fn () => null))->toBe($r);
});

it('demotes capability failures on the ladder but a GrantDenied propagates', function () {
    expect(Outcome::Timeout->demotesStrategyLadder())->toBeTrue()
        ->and(Outcome::NonZeroExit->demotesStrategyLadder())->toBeTrue()
        ->and(Outcome::GrantDenied->demotesStrategyLadder())->toBeFalse()
        ->and(Outcome::Success->demotesStrategyLadder())->toBeFalse();
});
