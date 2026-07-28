<?php

namespace Rushing\Popcorn\Runner;

/**
 * How a transform's value crosses the sandbox boundary (popcorn-runner ticket 07).
 *
 * - {@see Io::Stdio}  — the default; value is JSON on stdout, `ProcessInvocable`/`NullRunner`
 *   parity. stderr is the diagnostic side-channel.
 * - {@see Io::Files} — the pollution-immune escape hatch: stdout *and* stderr demote to
 *   bounded diagnostics and the value is read from a bound `/out/output.json`, so an
 *   author's stray `console.log`/`print` can't corrupt the value.
 */
enum Io: string
{
    case Stdio = 'stdio';
    case Files = 'files';
}
