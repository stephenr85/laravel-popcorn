<?php

namespace Rushing\Popcorn\Tests\Fixtures;

use Attribute;

/** A stand-in for a consumer's discovery attribute, so `Popcorn::discover()` has something to find. */
#[Attribute(Attribute::TARGET_CLASS)]
class ScannedThing {}
