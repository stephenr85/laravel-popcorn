<?php

namespace Rushing\Popcorn\Contracts;

use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Invocables\RunnerInvocable;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\NullRunner;
use Rushing\Popcorn\Runner\Result;

/**
 * The mechanism contract behind the {@see Invocable} socket (popcorn-runner ticket 01): execute a
 * {@see Manifest} under an *effective* {@see Grant} against `array $input`, returning a total
 * {@see Result}. **One implementation per isolation-backend, never per language** — the runtime
 * lives in the Manifest, so one `BwrapRunner` runs Node *or* Python via argv.
 *
 * Binding-orthogonal: a Runner is not a new {@see Binding} case. It is a *plug*
 * behind the Invocable socket, adapted into the array-in/array-out world by
 * {@see RunnerInvocable}, so the entire kernel (`CachedInvocable`,
 * `StrategyLadder`, registry override-by-name) composes over a sandboxed run unchanged.
 *
 * The kernel pulls in **no** bwrap/wasmtime dependency — the mechanics live entirely in the
 * substrate packages (`laravel-popcorn-bubble`, `laravel-popcorn-wasm`). The kernel ships only the
 * contract and the dependency-free {@see NullRunner}.
 */
interface Runner
{
    /**
     * @param  array<string, mixed>  $input  the per-invocation data payload
     * @return Result a total verdict — never throws for a *run* outcome (only for a kernel error)
     */
    public function run(Manifest $manifest, Grant $grant, array $input): Result;
}
