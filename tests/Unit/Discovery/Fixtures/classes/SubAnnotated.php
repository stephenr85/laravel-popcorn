<?php

declare(strict_types=1);

namespace Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\Classes;

use Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\ScanSubMarker;

#[ScanSubMarker(key: 'sub-annotated')]
class SubAnnotated {}
