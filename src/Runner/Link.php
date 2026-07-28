<?php

namespace Rushing\Popcorn\Runner;

/**
 * Javy linking mode for a wasm bundle (popcorn-runner ticket 08). Substrate metadata on
 * the {@see Manifest}, meaningful only to the Javy runtime.
 *
 * - {@see Link::Dynamic} — (default) a shared engine module + an engine-pinned user module;
 *   pair with {@see Manifest::$engineVersion}.
 * - {@see Link::Static}  — a self-contained, engine-independent module.
 */
enum Link: string
{
    case Dynamic = 'dynamic';
    case Static = 'static';
}
