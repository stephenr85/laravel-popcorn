<?php

use Illuminate\Support\Facades\Process;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Invocables\ProcessInvocable;

it('reports a local binding', function () {
    $invocable = new ProcessInvocable('otio.validate', ['python3', 'validate.py']);

    expect($invocable->name())->toBe('otio.validate')
        ->and($invocable->binding())->toBe(Binding::Local);
});

it('passes the input as JSON over stdin and decodes JSON stdout', function () {
    Process::fake([
        '*' => Process::result(output: json_encode(['valid' => true, 'semantic' => true])),
    ]);

    $invocable = new ProcessInvocable('otio.validate', ['python3', 'validate.py']);

    expect($invocable->invoke(['otio' => ['OTIO_SCHEMA' => 'Timeline.1']]))
        ->toBe(['valid' => true, 'semantic' => true]);

    Process::assertRan(function ($process) {
        return $process->command === ['python3', 'validate.py']
            && json_decode($process->input, true) === ['otio' => ['OTIO_SCHEMA' => 'Timeline.1']];
    });
});

it('throws when the subprocess exits non-zero, surfacing stderr', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'boom: bad otio', exitCode: 1),
    ]);

    $invocable = new ProcessInvocable('otio.validate', ['python3', 'validate.py']);

    expect(fn () => $invocable->invoke(['otio' => []]))
        ->toThrow(RuntimeException::class, 'boom: bad otio');
});

it('throws when stdout is not valid JSON', function () {
    Process::fake([
        '*' => Process::result(output: 'not json at all'),
    ]);

    $invocable = new ProcessInvocable('otio.validate', ['python3', 'validate.py']);

    expect(fn () => $invocable->invoke(['otio' => []]))
        ->toThrow(RuntimeException::class);
});

it('applies a configurable timeout to the subprocess', function () {
    Process::fake([
        '*' => Process::result(output: json_encode(['ok' => true])),
    ]);

    $invocable = new ProcessInvocable('slow.task', ['sleep', '1'], timeout: 5);

    $invocable->invoke([]);

    Process::assertRan(function ($process) {
        return $process->timeout === 5;
    });
});

it('exposes the description when provided', function () {
    $invocable = new ProcessInvocable('otio.validate', ['python3', 'validate.py'], description: 'OTIO validator');

    expect($invocable->description())->toBe('OTIO validator');
});
