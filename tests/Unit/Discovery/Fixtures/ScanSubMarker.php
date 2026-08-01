<?php

namespace Rushing\Popcorn\Tests\Unit\Discovery\Fixtures;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class ScanSubMarker extends ScanMarker {}
