<?php

use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\Classes\Annotated;
use Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\Classes\Plain;
use Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\Classes\SubAnnotated;
use Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\ScanMarker;
use Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\ScanSubMarker;

$path = __DIR__.'/Fixtures/classes';

it('finds only classes carrying the attribute under a scanned path', function () use ($path) {
    $found = (new AttributedClassScanner)->scan([$path], ScanMarker::class);

    // Annotated + SubAnnotated (instanceof default) match; Plain is ignored, not an error.
    expect($found)->toContain(Annotated::class)
        ->and($found)->toContain(SubAnnotated::class)
        ->and($found)->not->toContain(Plain::class);
});

it('honours the exact-match mode when instanceof is false', function () use ($path) {
    $found = (new AttributedClassScanner)->scan([$path], ScanMarker::class, instanceof: false);

    // SubAnnotated carries #[ScanSubMarker], not #[ScanMarker] exactly — excluded when instanceof=false.
    expect($found)->toContain(Annotated::class)
        ->and($found)->not->toContain(SubAnnotated::class);
});

it('matches preset subclass attributes by their own class', function () use ($path) {
    $found = (new AttributedClassScanner)->scan([$path], ScanSubMarker::class);

    expect($found)->toBe([SubAnnotated::class]);
});

it('skips non-existent paths and returns empty when none exist', function () {
    expect((new AttributedClassScanner)->scan(['/no/such/dir'], ScanMarker::class))->toBe([]);
});

it('derives a class-string from a file', function () use ($path) {
    $class = (new AttributedClassScanner)->classNameFromFile($path.'/Annotated.php');

    expect($class)->toBe(Annotated::class);
});
