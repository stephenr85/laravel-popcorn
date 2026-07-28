<?php

namespace Rushing\Popcorn\Runner;

/**
 * How a wasm bundle becomes a runnable module (popcorn-runner ticket 08). Substrate
 * metadata carried on the {@see Manifest} — meaningful only to wasm runtimes, a declared
 * no-op elsewhere. **Declared, never sniffed:** a resolved Manifest always carries the
 * explicit mode so "does this need a toolchain" is knowable before the run.
 *
 * - {@see Build::Prebuilt} — the bundle ships a runnable `.wasm`; instantiate only, no host
 *   toolchain. The published/prod shape.
 * - {@see Build::Source}   — the bundle ships source the substrate compiles-and-caches once
 *   (never per-invocation). The local-dev shape.
 */
enum Build: string
{
    case Prebuilt = 'prebuilt';
    case Source = 'source';
}
