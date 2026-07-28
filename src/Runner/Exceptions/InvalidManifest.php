<?php

namespace Rushing\Popcorn\Runner\Exceptions;

use RuntimeException;
use Rushing\Popcorn\Runner\Result;

/**
 * A `popcorn.json` (or its decoded array) was missing a required field or otherwise
 * unparseable. This is a **kernel/programmer error** — the one place the Runner seam throws
 * eagerly rather than returning a total {@see Result} (ticket 06):
 * a malformed Manifest is not a run *outcome*, it means there is nothing runnable to describe.
 */
class InvalidManifest extends RuntimeException {}
