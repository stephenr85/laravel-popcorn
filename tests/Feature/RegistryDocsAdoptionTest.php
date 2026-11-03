<?php

use Rushing\Popcorn\Registries\Docs\RegistryDocsCheck;

/*
 * laravel-popcorn adopts the package-tier registry docs check (registry-docs ticket 04). It is
 * inactive until this package grows `docs/registries/` (the describe-gate ratchet); from then on every
 * root declared under `src/` must be covered.
 */
it('documents every registry root this package declares, once it opts in with docs/registries/', function () {
    $report = RegistryDocsCheck::forPackage(dirname(__DIR__, 2))->run();

    expect($report->failures())->toBe([], $report->summary());
});
