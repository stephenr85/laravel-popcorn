<?php

declare(strict_types=1);

namespace Rushing\Popcorn\Tests\Unit\Discovery\Fixtures;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class ScanMarker
{
    public function __construct(public string $key = '') {}
}
