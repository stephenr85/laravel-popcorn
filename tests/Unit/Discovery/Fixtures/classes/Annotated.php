<?php

declare(strict_types=1);

namespace Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\Classes;

use Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\ScanMarker;

#[ScanMarker(key: 'annotated')]
class Annotated {}
