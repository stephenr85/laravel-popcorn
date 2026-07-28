<?php

use Illuminate\Support\Facades\Process;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Limits;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Net;
use Rushing\Popcorn\Runner\NullRunner;
use Rushing\Popcorn\Runner\Outcome;

function nullManifest(string $runtime = 'node@22'): Manifest
{
    return Manifest::fromArray(['name' => 't', 'runtime' => $runtime, 'entrypoint' => 'index.js']);
}

it('echoes input for the reserved echo runtime without a subprocess', function () {
    Process::fake();

    $r = (new NullRunner)->run(nullManifest('echo'), Grant::none(), ['a' => 1]);

    expect($r->outcome)->toBe(Outcome::Success)->and($r->output())->toBe(['a' => 1]);
    Process::assertNothingRan();
});

it('runs the interpreter argv and decodes JSON stdout as array-out', function () {
    Process::fake(['*' => Process::result(output: json_encode(['ok' => true]))]);

    $r = (new NullRunner)->run(nullManifest('python@3.12'), Grant::none(), ['x' => 1]);

    expect($r->successful())->toBeTrue()
        ->and($r->output())->toBe(['ok' => true])
        ->and($r->sandboxed)->toBeFalse()
        ->and($r->wallMs)->not->toBeNull();

    Process::assertRan(fn ($p) => $p->command === ['python3', 'index.js']);
});

it('reflects the effective grant in-band as the {input, grant} stdin envelope', function () {
    Process::fake(['*' => Process::result(output: '{}')]);

    (new NullRunner)->run(nullManifest(), new Grant(net: Net::Scoped), ['q' => 5]);

    Process::assertRan(function ($p) {
        $sent = json_decode($p->input, true);

        return $sent['input'] === ['q' => 5] && $sent['grant']['net'] === 'scoped';
    });
});

it('maps a non-zero exit to NonZeroExit with a bounded stderr tail', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'traceback boom', exitCode: 1)]);

    $r = (new NullRunner)->run(nullManifest(), Grant::none(), []);

    expect($r->outcome)->toBe(Outcome::NonZeroExit)
        ->and($r->exitCode)->toBe(1)
        ->and($r->stderr)->toContain('boom');
});

it('maps non-JSON stdout to MalformedOutput', function () {
    Process::fake(['*' => Process::result(output: 'not json')]);

    $r = (new NullRunner)->run(nullManifest(), Grant::none(), []);

    expect($r->outcome)->toBe(Outcome::MalformedOutput);
});

it('honors the wall-time ceiling from the grant limits', function () {
    Process::fake(['*' => Process::result(output: '{}')]);

    (new NullRunner)->run(nullManifest(), new Grant(limits: new Limits(wallMs: 5000)), []);

    Process::assertRan(fn ($p) => $p->timeout === 5);
});

it('passes the env allowlist to the subprocess', function () {
    Process::fake(['*' => Process::result(output: '{}')]);

    (new NullRunner)->run(nullManifest(), new Grant(env: ['API' => 'https://x']), []);

    Process::assertRan(fn ($p) => ($p->environment['API'] ?? null) === 'https://x');
});
