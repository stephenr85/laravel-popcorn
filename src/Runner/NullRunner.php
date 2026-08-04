<?php

namespace Rushing\Popcorn\Laravel\Runner;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use JsonException;
use Rushing\Popcorn\Contracts\Runner;
use Rushing\Popcorn\Runner\Concerns\HandlesRunnerIo;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\Result;

/**
 * The kernel-shipped, dependency-free {@see Runner}: it runs the Manifest's argv with **no
 * isolation** and returns a total {@see Result} with wall-time telemetry (popcorn-runner ticket 01).
 * `ProcessInvocable` re-expressed as "a Runner over an empty Grant" — the CI-fast / pure-wiring path,
 * and the honest local fallback where no real substrate is available.
 *
 * Because it enforces nothing, its {@see Result::$sandboxed} is always `false`. It *does* honor the
 * two host-side conveniences a bare subprocess can: the `env` allowlist and the wall-time ceiling
 * (via `Process::timeout()`). It reflects the effective Grant in-band (ticket 05's run-time
 * documented environment): the stdin envelope is `{ "input": …, "grant": … }`, and every substrate
 * follows the same convention so a transform reads its capabilities the same way everywhere.
 *
 * The `runtime` maps to a host interpreter (`python@3.12` → `python3`, `node@22` → `node`, else the
 * bare id); the reserved runtime `echo` short-circuits to echoing the input — a subprocess-free
 * wiring assertion.
 */
class NullRunner implements Runner
{
    use HandlesRunnerIo;

    private const DEFAULT_WALL_SECONDS = 60;

    public function run(Manifest $manifest, Grant $grant, array $input): Result
    {
        if ($manifest->runtime === 'echo') {
            return Result::success(rawOutput: $this->encode($input), stderr: '');
        }

        try {
            $payload = $this->encode(['input' => $input, 'grant' => $grant->toArray()]);
        } catch (JsonException $e) {
            return new Result(Outcome::MalformedOutput, error: "popcorn: cannot encode input: {$e->getMessage()}");
        }

        $timeoutSeconds = $grant->limits->wallMs !== null
            ? (int) max(1, ceil($grant->limits->wallMs / 1000))
            : self::DEFAULT_WALL_SECONDS;

        $pending = Process::input($payload)->timeout($timeoutSeconds);

        if ($grant->env !== []) {
            $pending = $pending->env($grant->env);
        }

        $startedAt = microtime(true);

        try {
            $result = $pending->run($this->argv($manifest));
        } catch (ProcessTimedOutException $e) {
            return new Result(
                Outcome::Timeout,
                error: "popcorn: run timed out after {$timeoutSeconds}s.",
                wallMs: (int) round((microtime(true) - $startedAt) * 1000),
                limitHit: true,
                sandboxed: false,
            );
        }

        $wallMs = (int) round((microtime(true) - $startedAt) * 1000);
        $stderr = $this->tail($result->errorOutput(), self::STDERR_TAIL_BYTES);
        $stderrTruncated = strlen($result->errorOutput()) > self::STDERR_TAIL_BYTES;

        if ($result->failed()) {
            return new Result(
                Outcome::NonZeroExit,
                stderr: $stderr,
                stderrTruncated: $stderrTruncated,
                error: trim($result->errorOutput()) ?: "popcorn: process exited {$result->exitCode()}.",
                wallMs: $wallMs,
                exitCode: $result->exitCode(),
                sandboxed: false,
            );
        }

        $raw = $result->output();

        if (strlen($raw) > self::OUTPUT_HARD_CAP_BYTES) {
            return new Result(
                Outcome::MalformedOutput,
                stderr: $stderr,
                stderrTruncated: $stderrTruncated,
                error: 'popcorn: output exceeded the hard cap; refused rather than truncated.',
                wallMs: $wallMs,
                exitCode: $result->exitCode(),
                sandboxed: false,
            );
        }

        if (! $this->isJsonObject(trim($raw))) {
            return new Result(
                Outcome::MalformedOutput,
                rawOutput: $raw,
                stderr: $stderr,
                stderrTruncated: $stderrTruncated,
                error: 'popcorn: output was not a JSON object.',
                wallMs: $wallMs,
                exitCode: $result->exitCode(),
                sandboxed: false,
            );
        }

        return new Result(
            Outcome::Success,
            rawOutput: $raw,
            stderr: $stderr,
            stderrTruncated: $stderrTruncated,
            wallMs: $wallMs,
            exitCode: $result->exitCode(),
            sandboxed: false,
        );
    }

    /** @return list<string> */
    private function argv(Manifest $manifest): array
    {
        return [$this->interpreterFor($manifest->runtime), $manifest->entrypointPath()];
    }

    private function interpreterFor(string $runtime): string
    {
        $base = strtok($runtime, '@') ?: $runtime;

        return match (true) {
            str_starts_with($base, 'python') => 'python3',
            str_starts_with($base, 'node') => 'node',
            default => $base,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws JsonException
     */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
