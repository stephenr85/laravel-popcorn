<?php

namespace Rushing\Popcorn\Runner\Concerns;

use JsonException;
use Rushing\Popcorn\Contracts\Runner;
use Rushing\Popcorn\Runner\NullRunner;

/**
 * The shared I/O plumbing every {@see Runner} needs: the bounded-value /
 * bounded-diagnostic caps, JSON-object validation, a stderr tail, and host-binary probing. Lives in
 * the kernel so the substrates ({@see NullRunner} + the bubble/wasm Runners)
 * share one implementation — a fix to the JSON check or the tail logic is one edit, not three.
 */
trait HandlesRunnerIo
{
    /** Hard cap on the captured value channel; a breach is a MalformedOutput, never a silent truncation. */
    protected const OUTPUT_HARD_CAP_BYTES = 262144; // 256 KiB

    /** Tail cap on the diagnostic side-channel — the last N bytes, since a diagnostic is at the end. */
    protected const STDERR_TAIL_BYTES = 16384; // 16 KiB

    protected function isJsonObject(string $candidate): bool
    {
        try {
            return is_array(json_decode($candidate, true, flags: JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return false;
        }
    }

    protected function tail(string $value, int $bytes): string
    {
        return strlen($value) > $bytes ? substr($value, -$bytes) : $value;
    }

    protected function binaryOnPath(string $binary): bool
    {
        if (str_contains($binary, '/')) {
            return is_executable($binary);
        }

        $which = @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');

        return is_string($which) && trim($which) !== '';
    }
}
